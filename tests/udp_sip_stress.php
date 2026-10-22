<?php
declare(strict_types=1);

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegisterManager.php';

use helpers\utils\SipRegisterManager;
use libspech\Sip\sip;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

final class StressTransport
{
    public function __construct(private Socket $socket) {}
    public function sendto(string $ip, int $port, string $packet): int|false { return $this->socket->sendto($ip, $port, $packet); }
}

function stressResponse(array $request, int $code): string
{
    $status = $code === 200 ? '200 OK' : '401 Unauthorized';
    $headers = [];
    foreach (['Via', 'From', 'To', 'Call-ID', 'CSeq'] as $key) $headers[$key] = $request['headers'][$key] ?? [];
    if ($code === 401) $headers['WWW-Authenticate'] = ['Digest realm="stress.local", nonce="load-nonce", algorithm=MD5, qop="auth"'];
    else {
        $headers['Contact'] = [$request['headers']['Contact'][0] . ';expires=1800'];
        $headers['Expires'] = ['1800'];
    }
    $packet = "SIP/2.0 {$status}\r\n";
    foreach ($headers as $key => $values) foreach ($values as $value) $packet .= "{$key}: {$value}\r\n";
    return $packet . "Content-Length: 0\r\n\r\n";
}

$runtimeStart = hrtime(true);
Coroutine\run(function () use ($runtimeStart): void {
    SipRegisterManager::setLocalIpResolverForTests(static fn(): string => '127.0.0.1');
    $client = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$client->bind('127.0.0.1', SipRegisterManager::SIP_PORT)) throw new RuntimeException('cannot bind UDP :4000');
    $provider = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$provider->bind('127.0.0.1', 0)) throw new RuntimeException('cannot bind local provider');
    $port = (int)($provider->getsockname()['port'] ?? 0);
    if ($port < 1) throw new RuntimeException('cannot find local provider port');
    $transport = new StressTransport($client);
    $running = true;
    $sent = 0;
    $monitorBatch = false;
    $peakCoroutines = 0;
    $peakPending = 0;
    go(function () use (&$running, &$monitorBatch, &$peakCoroutines, &$peakPending): void {
        while ($running) {
            if ($monitorBatch) {
                $peakCoroutines = max($peakCoroutines, (int)(Coroutine::stats()['coroutine_num'] ?? 0));
                $peakPending = max($peakPending, SipRegisterManager::pendingCount());
            }
            Coroutine::sleep(0.001);
        }
    });
    go(function () use ($client, &$running): void {
        while ($running) {
            $peer = [];
            $packet = $client->recvfrom($peer, 0.1);
            if (is_string($packet) && $packet !== '') SipRegisterManager::handleResponse(sip::parse($packet), $peer);
        }
    });
    go(function () use ($provider, &$running, &$sent): void {
        while ($running) {
            $peer = [];
            $packet = $provider->recvfrom($peer, 0.1);
            if (!is_string($packet) || $packet === '') continue;
            $request = sip::parse($packet);
            if (($request['method'] ?? '') !== 'REGISTER') continue;
            $sent++;
            $code = isset($request['headers']['Authorization']) ? 200 : 401;
            $provider->sendto($peer['address'], $peer['port'], stressResponse($request, $code));
        }
    });

    $metrics = [];
    foreach ([1, 10, 50, 100] as $count) {
        $channel = new Channel($count);
        $start = hrtime(true);
        $datagramsBefore = $sent;
        $memoryBefore = memory_get_usage(true);
        $cpuBefore = getrusage();
        $peakCoroutines = 0;
        $peakPending = 0;
        $monitorBatch = true;
        for ($i = 0; $i < $count; $i++) {
            go(function () use ($i, $count, $port, $transport, $channel): void {
                $result = SipRegisterManager::register($transport, [
                    'sipServer' => "127.0.0.1:{$port}", 'sipDomain' => 'stress.local',
                    'sipUser' => 'shared-aor', 'sipPass' => 'local-only', 'accountId' => "batch-{$count}-device-{$i}",
                ], 1800, 12.0);
                $channel->push($result, 13.0);
            });
        }
        $successes = 0;
        $failures = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $channel->pop(15.0);
            if (!is_array($result)) { $failures[] = 'worker_timeout'; continue; }
            if (!empty($result['success'])) $successes++;
            else $failures[] = (string)($result['reason'] ?? 'unknown');
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $monitorBatch = false;
        $datagrams = $sent - $datagramsBefore;
        $cpuAfter = getrusage();
        $metrics[] = [
            'accounts' => $count, 'successes' => $successes, 'failures' => $failures,
            'register_datagrams' => $datagrams, 'registers_per_second' => round($datagrams / max($elapsed, 0.001), 2),
            'retransmissions' => max(0, $datagrams - 2 * $count),
            'elapsed_seconds' => round($elapsed, 3), 'active_coroutines' => Coroutine::stats()['coroutine_num'] ?? null,
            'average_authentication_ms' => round($elapsed * 1000 / max($count, 1), 3),
            'peak_active_coroutines' => $peakCoroutines,
            'peak_pending_transactions' => $peakPending,
            'pending_transactions' => SipRegisterManager::pendingCount(),
            'memory_delta_bytes' => memory_get_usage(true) - $memoryBefore,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'cpu_user_seconds' => round((($cpuAfter['ru_utime.tv_sec'] + $cpuAfter['ru_utime.tv_usec'] / 1e6)
                - ($cpuBefore['ru_utime.tv_sec'] + $cpuBefore['ru_utime.tv_usec'] / 1e6)), 4),
        ];
        if ($successes !== $count || $failures !== [] || SipRegisterManager::pendingCount() !== 0) {
            throw new RuntimeException('SIP stress batch failed: ' . json_encode(end($metrics), JSON_UNESCAPED_SLASHES));
        }
        $channel->close();
    }
    $running = false;
    $client->close();
    $provider->close();
    SipRegisterManager::setLocalIpResolverForTests(null);
    echo json_encode(['suite' => 'loopback-udp-digest-stress', 'runtime_seconds' => round((hrtime(true) - $runtimeStart) / 1e9, 3),
        'batches' => $metrics, 'pending_after_cleanup' => SipRegisterManager::pendingCount()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
});
