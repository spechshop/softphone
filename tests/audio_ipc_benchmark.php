<?php

declare(strict_types=1);

require_once __DIR__ . '/../plugins/Utils/helpers/AudioIpcPacket.php';

use helpers\utils\AudioIpcPacket;

function benchmarkPercentile(array $values, int $percentile): float
{
    if ($values === []) return 0.0;
    sort($values, SORT_NUMERIC);
    $index = max(0, min(count($values) - 1, (int)ceil(count($values) * $percentile / 100) - 1));
    return round((float)$values[$index], 1);
}

function benchmarkCpuUs(array $usage): int
{
    return ((int)$usage['ru_utime.tv_sec'] + (int)$usage['ru_stime.tv_sec']) * 1_000_000
        + (int)$usage['ru_utime.tv_usec'] + (int)$usage['ru_stime.tv_usec'];
}

function benchmarkTransport(string $transport, int $packets, int $payloadBytes): array
{
    $domain = $transport === 'unix' ? AF_UNIX : AF_INET;
    $receiver = socket_create($domain, SOCK_DGRAM, 0);
    $sender = socket_create($domain, SOCK_DGRAM, 0);
    if ($receiver === false || $sender === false) throw new RuntimeException("{$transport}: socket_create failed");
    socket_set_option($receiver, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

    $socketPath = null;
    if ($transport === 'unix') {
        $socketPath = sys_get_temp_dir() . '/spechphone-audio-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
        if (!socket_bind($receiver, $socketPath)) throw new RuntimeException('unix bind failed');
        $address = $socketPath;
        $port = null;
    } else {
        if (!socket_bind($receiver, '127.0.0.1', 0)) throw new RuntimeException('udp bind failed');
        $address = '';
        $port = 0;
        socket_getsockname($receiver, $address, $port);
    }

    $payload = str_repeat("\x01\x00", intdiv($payloadBytes + 1, 2));
    $payload = substr($payload, 0, $payloadBytes);
    $latencyUs = [];
    $gapsUs = [];
    $drops = 0;
    $lastReceiveNs = null;
    $usageBefore = getrusage();
    $startedNs = hrtime(true);

    try {
        for ($sequence = 0; $sequence < $packets; $sequence++) {
            $datagram = (new AudioIpcPacket($payload, 'bench', 'source', 8000, 1))->encode();
            $sent = $transport === 'unix'
                ? socket_sendto($sender, $datagram, strlen($datagram), 0, $address)
                : socket_sendto($sender, $datagram, strlen($datagram), 0, $address, $port);
            if ($sent !== strlen($datagram)) {
                $drops++;
                continue;
            }

            $from = '';
            $fromPort = 0;
            $received = socket_recvfrom($receiver, $raw, 65535, 0, $from, $fromPort);
            $receivedNs = hrtime(true);
            if ($received === false) {
                $drops++;
                continue;
            }
            $decoded = AudioIpcPacket::decode($raw);
            if (!$decoded instanceof AudioIpcPacket) {
                $drops++;
                continue;
            }
            $latencyUs[] = ($receivedNs - $decoded->sentAtNs) / 1000;
            if ($lastReceiveNs !== null) $gapsUs[] = ($receivedNs - $lastReceiveNs) / 1000;
            $lastReceiveNs = $receivedNs;
        }
    } finally {
        $elapsedNs = hrtime(true) - $startedNs;
        $usageAfter = getrusage();
        socket_close($sender);
        socket_close($receiver);
        if ($socketPath !== null && file_exists($socketPath)) unlink($socketPath);
    }

    $receivedPackets = count($latencyUs);
    return [
        'transport' => $transport,
        'packetsRequested' => $packets,
        'packetsReceived' => $receivedPackets,
        'payloadBytes' => $payloadBytes,
        'throughputPacketsPerSecond' => round($receivedPackets / max(0.000001, $elapsedNs / 1e9)),
        'cpuUs' => benchmarkCpuUs($usageAfter) - benchmarkCpuUs($usageBefore),
        'latencyUsP50' => benchmarkPercentile($latencyUs, 50),
        'latencyUsP95' => benchmarkPercentile($latencyUs, 95),
        'latencyUsP99' => benchmarkPercentile($latencyUs, 99),
        'receiveGapUsP95' => benchmarkPercentile($gapsUs, 95),
        'receiveGapUsP99' => benchmarkPercentile($gapsUs, 99),
        'drops' => $drops,
        'hotPathSyscallsPerPacket' => 2,
    ];
}

$options = getopt('', ['transport::', 'packets::', 'payload-bytes::']);
$transport = strtolower((string)($options['transport'] ?? 'both'));
$packets = max(100, (int)($options['packets'] ?? 20000));
$payloadBytes = max(160, min(60000, (int)($options['payload-bytes'] ?? 320)));
if (!in_array($transport, ['udp', 'unix', 'both'], true)) {
    fwrite(STDERR, "--transport must be udp, unix or both\n");
    exit(2);
}

$results = [];
if ($transport !== 'unix') $results[] = benchmarkTransport('udp', $packets, $payloadBytes);
if ($transport !== 'udp') $results[] = benchmarkTransport('unix', $packets, $payloadBytes);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
