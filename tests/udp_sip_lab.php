<?php
declare(strict_types=1);

require __DIR__ . '/../libspech/plugins/autoloader.php';
require __DIR__ . '/../plugins/Utils/helpers/SipRegisterManager.php';

use helpers\utils\SipRegisterManager;
use libspech\Sip\sip;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

function labAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

final class LabUdpTransport
{
    public function __construct(public Socket $socket) {}
    public function sendto(string $ip, int $port, string $packet): int|false
    {
        return $this->socket->sendto($ip, $port, $packet);
    }
}

function labResponse(array $request, int $code): string
{
    $headers = [];
    foreach (['Via', 'From', 'To', 'Call-ID', 'CSeq'] as $header) {
        $headers[$header] = $request['headers'][$header] ?? [];
    }
    if ($code === 401) {
        $headers['WWW-Authenticate'] = ['Digest realm="lab.local", nonce="udp-lab-nonce", algorithm=MD5, qop="auth"'];
    } elseif ($code === 200) {
        $headers['Contact'] = [$request['headers']['Contact'][0] . ';expires=1800'];
        $headers['Expires'] = ['1800'];
    }
    $reason = [200 => 'OK', 401 => 'Unauthorized', 403 => 'Forbidden'][$code] ?? 'Error';
    $raw = "SIP/2.0 {$code} {$reason}\r\n";
    foreach ($headers as $name => $values) foreach ($values as $value) $raw .= "{$name}: {$value}\r\n";
    return $raw . "Content-Length: 0\r\n\r\n";
}

SipRegisterManager::setLocalIpResolverForTests(static fn(): string => '127.0.0.1');
Coroutine\run(function (): void {
    $clientSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$clientSocket->bind('127.0.0.1', SipRegisterManager::SIP_PORT)) {
        throw new RuntimeException('Cannot bind isolated SIP client UDP :4000: ' . $clientSocket->errMsg);
    }
    $provider = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$provider->bind('127.0.0.1', 0)) throw new RuntimeException('Cannot bind SIP lab UDP socket');
    $socketAddress = $provider->getsockname();
    $labPort = (int)($socketAddress['port'] ?? 0);
    if ($labPort < 1) throw new RuntimeException('Cannot discover lab port');
    $transport = new LabUdpTransport($clientSocket);
    $running = true;
    $requestCount = 0;
    $mode = 'digest';
    go(function () use ($clientSocket, &$running): void {
        while ($running) {
            $peer = [];
            $packet = $clientSocket->recvfrom($peer, 0.1);
            if (!is_string($packet) || $packet === '') continue;
            SipRegisterManager::handleResponse(sip::parse($packet), $peer);
        }
    });
    go(function () use ($provider, &$running, &$requestCount, &$mode): void {
        while ($running) {
            $peer = [];
            $packet = $provider->recvfrom($peer, 0.25);
            if (!is_string($packet) || $packet === '') continue;
            $request = sip::parse($packet);
            if (($request['method'] ?? '') !== 'REGISTER') continue;
            $requestCount++;
            if ($mode === 'drop') continue;
            $authorized = isset($request['headers']['Authorization']);
            if ($mode === 'forbidden') {
                $provider->sendto($peer['address'], $peer['port'], labResponse($request, 403));
                continue;
            }
            $response = labResponse($request, $authorized ? 200 : 401);
            $provider->sendto($peer['address'], $peer['port'], $response);
            if ($authorized) $provider->sendto($peer['address'], $peer['port'], $response); // duplicate final packet
        }
    });

    try {
        $result = SipRegisterManager::register($transport, [
            'sipServer' => "127.0.0.1:{$labPort}", 'sipDomain' => 'lab.local',
            'sipUser' => 'udp-lab', 'sipPass' => 'local-test-secret', 'accountId' => 'udp-lab-device',
        ], 1800, 2.0);
        labAssert($result['success'] === true, 'UDP Digest registration failed: ' . ($result['reason'] ?? 'unknown')
            . " requests={$requestCount} peerPort={$labPort} clientErr={$clientSocket->errCode}/{$clientSocket->errMsg}");
        labAssert($requestCount === 2, "expected unauthenticated challenge and digest request, received {$requestCount}");
        labAssert(($result['authenticated'] ?? false) === true, 'registered state lacks authenticated result');
        labAssert(($result['source_port'] ?? null) === SipRegisterManager::SIP_PORT, 'client did not use SIP UDP port 4000');
        labAssert(SipRegisterManager::pendingCount() === 0, 'pending transaction leaked after UDP 200 OK');
        $mode = 'drop';
        $beforeTimeout = $requestCount;
        $timeout = SipRegisterManager::register($transport, [
            'sipServer' => "127.0.0.1:{$labPort}", 'sipDomain' => 'lab.local',
            'sipUser' => 'udp-timeout', 'sipPass' => 'local-test-secret', 'accountId' => 'udp-timeout-device',
        ], 1800, 1.2);
        labAssert(!$timeout['success'] && $timeout['reason'] === 'timeout', 'silent UDP provider must time out');
        $retryDatagrams = $requestCount - $beforeTimeout;
        labAssert($retryDatagrams >= 2 && $retryDatagrams <= 4, "UDP retransmits must be bounded, observed={$retryDatagrams}");
        labAssert(SipRegisterManager::pendingCount() === 0, 'pending transaction leaked after UDP timeout');
        $mode = 'forbidden';
        $forbidden = SipRegisterManager::register($transport, [
            'sipServer' => "127.0.0.1:{$labPort}", 'sipDomain' => 'lab.local',
            'sipUser' => 'udp-forbidden', 'sipPass' => 'local-test-secret', 'accountId' => 'udp-forbidden-device',
        ], 1800, 1.0);
        labAssert(!$forbidden['success'] && $forbidden['code'] === 403, '403 must be returned as a definitive rejection');
        labAssert(SipRegisterManager::pendingCount() === 0, 'pending transaction leaked after UDP 403');
        echo "PASS UDP local lab: digest=2 datagrams, duplicate final response, timeout retransmits={$retryDatagrams}, 403, pending=" . SipRegisterManager::pendingCount() . "\n";
    } finally {
        $running = false;
        $provider->close();
        $clientSocket->close();
    }
});
SipRegisterManager::setLocalIpResolverForTests(null);
