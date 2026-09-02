<?php

declare(strict_types=1);

require_once __DIR__ . '/../plugins/Utils/helpers/AudioIpcPacket.php';

use helpers\utils\AudioIpcPacket;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Socket;

$options = getopt('', [
    'streams::',
    'frames::',
    'server-root::',
    'legacy-ipc',
    'metrics-interval::',
    'playback-batch-ms::',
    'source-rate::',
    'intermediate-rate::',
    'target-rate::',
]);
$tiers = isset($options['streams'])
    ? array_values(array_filter(array_map('intval', explode(',', (string)$options['streams'])), static fn(int $v): bool => $v > 0))
    : [10, 30, 50, 100];
$framesPerTier = max(10, (int)($options['frames'] ?? 100));
$serverRoot = isset($options['server-root']) ? realpath((string)$options['server-root']) : dirname(__DIR__);
if (!is_string($serverRoot) || !is_file($serverRoot . '/audio.php')) {
    throw new RuntimeException('invalid --server-root');
}
$legacyIpc = array_key_exists('legacy-ipc', $options);
$metricsInterval = max(1, min(60, (int)($options['metrics-interval'] ?? 1)));
$playbackBatchMs = max(10, min(120, (int)($options['playback-batch-ms'] ?? 120)));
$sourceRate = max(8000, min(48000, (int)($options['source-rate'] ?? 8000)));
$intermediateRate = max(8000, min(48000, (int)($options['intermediate-rate'] ?? $sourceRate)));
$targetRate = max(8000, min(48000, (int)($options['target-rate'] ?? $sourceRate)));

function playbackProcessSample(int $pid): array
{
    $stat = @file_get_contents("/proc/{$pid}/stat");
    $status = @file_get_contents("/proc/{$pid}/status");
    $cpuTicks = 0;
    if (is_string($stat) && preg_match('/^\d+ \(.+\) (.+)$/', trim($stat), $match)) {
        $fields = preg_split('/\s+/', $match[1]);
        $cpuTicks = (int)($fields[11] ?? 0) + (int)($fields[12] ?? 0);
    }
    $rssKb = is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match)
        ? (int)$match[1]
        : 0;
    $fdEntries = @scandir("/proc/{$pid}/fd");
    $fdCount = is_array($fdEntries) ? max(0, count($fdEntries) - 2) : 0;
    return [$cpuTicks, $rssKb, $fdCount];
}

function playbackReadLogs(string $path): string
{
    return (string)file_get_contents($path);
}

function playbackAggregateMetrics(string $logs, string $prefix): array
{
    $aggregate = [
        'ipcLatencyUsP95' => 0.0,
        'ipcLatencyUsP99' => 0.0,
        'ipcProcessingUsP95' => 0.0,
        'ipcProcessingUsP99' => 0.0,
        'ipcQueueDelayUsP95' => 0.0,
        'ipcQueueDelayUsP99' => 0.0,
        'ipcQueuePeak' => 0,
        'ipcDrops' => 0,
        'resampleCalls' => 0,
    ];
    foreach (preg_split('/\R/', $logs) as $line) {
        if (!str_contains($line, '[AUDIO:PIPELINE] stream=' . $prefix)) continue;
        $jsonStart = strpos($line, '{');
        $jsonEnd = strrpos($line, '}');
        if ($jsonStart === false || $jsonEnd === false || $jsonEnd < $jsonStart) continue;
        $metric = json_decode(substr($line, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        if (!is_array($metric)) continue;
        foreach ($aggregate as $key => $value) {
            $aggregate[$key] = max($value, $metric[$key] ?? 0);
        }
    }
    return $aggregate;
}

$cwd = $serverRoot;
$pipes = [];
$logPath = tempnam(sys_get_temp_dir(), 'spechphone-playback-stress-');
if ($logPath === false) throw new RuntimeException('could not create stress log');
$environment = array_merge(getenv(), [
    'AUDIO_METRICS_INTERVAL_SECONDS' => (string)$metricsInterval,
    'AUDIO_PLAYBACK_BATCH_MS' => (string)$playbackBatchMs,
]);
$process = proc_open(
    [PHP_BINARY, 'audio.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
    $pipes,
    $cwd,
    $environment,
);
if (!is_resource($process)) throw new RuntimeException('could not start audio.php');
$status = proc_get_status($process);
$pid = (int)$status['pid'];
$logs = '';

try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $logs = playbackReadLogs($logPath);
        if (str_contains($logs, 'Servidor UDP aguardando pacotes')) {
            $ready = true;
            break;
        }
        usleep(20_000);
    }
    if (!$ready) throw new RuntimeException('audio.php did not become ready: ' . $logs);

    [$baselineTicks, $baselineRssKb, $baselineFdCount] = playbackProcessSample($pid);
    $results = [];
    Swoole\Coroutine\run(function () use (
        &$results,
        &$logs,
        $logPath,
        $pid,
        $tiers,
        $framesPerTier,
        $legacyIpc,
        $sourceRate,
        $intermediateRate,
        $targetRate,
    ): void {
        foreach ($tiers as $streamCount) {
            fwrite(STDERR, "starting playback stress tier={$streamCount}\n");
            $prefix = "playback-{$streamCount}-" . bin2hex(random_bytes(3));
            $clients = [];
            for ($index = 0; $index < $streamCount; $index++) {
                $stream = "{$prefix}-{$index}";
                $client = new Client('127.0.0.1', 8889, true);
                $client->set([
                    'timeout' => 2,
                    'ssl_verify_peer' => false,
                    'ssl_allow_self_signed' => true,
                ]);
                if (!$client->upgrade("/?stream={$stream}&ssrc=rx-{$index}&sampleRate={$targetRate}&channels=1")) {
                    throw new RuntimeException("WSS upgrade failed for {$stream}: {$client->statusCode}/{$client->errCode}");
                }
                $clients[$stream] = $client;
            }

            $udp = new Socket(AF_INET, SOCK_DGRAM, 0);
            $pcm = str_repeat("\x00\x00", (int)round($sourceRate * 0.02));
            [$ticksBefore] = playbackProcessSample($pid);
            $tierStartedNs = hrtime(true);
            $rssPeakKb = 0;
            $sentPackets = 0;
            for ($frame = 0; $frame < $framesPerTier; $frame++) {
                $deadlineNs = $tierStartedNs + ($frame * 20_000_000);
                foreach (array_keys($clients) as $stream) {
                    $datagram = $legacyIpc
                        ? $pcm . "__::__{$stream}__::__rtp-source__::__0__::__{$intermediateRate}__::__{$sourceRate}__::__1"
                        : (new AudioIpcPacket($pcm, $stream, 'rtp-source', $sourceRate, 1))->encode();
                    if ($udp->sendto('127.0.0.1', 9966, $datagram) > 0) $sentPackets++;
                }
                [, $rssKb] = playbackProcessSample($pid);
                $rssPeakKb = max($rssPeakKb, $rssKb);
                $remainingNs = $deadlineNs + 20_000_000 - hrtime(true);
                if ($remainingNs > 0) Swoole\Coroutine::sleep($remainingNs / 1_000_000_000);
            }
            Swoole\Coroutine::sleep(0.1);
            $logs = playbackReadLogs($logPath);
            [$ticksAfter] = playbackProcessSample($pid);
            $elapsedMs = (hrtime(true) - $tierStartedNs) / 1_000_000;

            $deliveredStreams = 0;
            foreach ($clients as $client) {
                $frame = $client->recv(0.5);
                if (is_object($frame) && ($frame->opcode ?? null) === SWOOLE_WEBSOCKET_OPCODE_BINARY) {
                    $deliveredStreams++;
                }
                $client->close();
            }
            $udp->close();
            Swoole\Coroutine::sleep(0.1);

            $clockTicks = max(1, (int)(getenv('CLK_TCK') ?: 100));
            $cpuMs = (($ticksAfter - $ticksBefore) / $clockTicks) * 1000;
            $results[] = [
                'streams' => $streamCount,
                'durationMs' => round($elapsedMs, 1),
                'sentPackets' => $sentPackets,
                'deliveredStreams' => $deliveredStreams,
                'ipcProtocol' => $legacyIpc ? 'legacy-text' : 'binary-v1',
                'route' => "{$sourceRate}->{$targetRate}",
                'serverCpuMs' => round($cpuMs, 1),
                'serverCpuPercentOfOneCore' => round(($cpuMs / max(1, $elapsedMs)) * 100, 1),
                'serverRssPeakMb' => round($rssPeakKb / 1024, 1),
                ...playbackAggregateMetrics($logs, $prefix),
            ];
            fwrite(STDERR, "completed playback stress tier={$streamCount}\n");
        }
    });

    usleep(1_200_000);
    $logs = playbackReadLogs($logPath);
    [$finalTicks, $finalRssKb, $finalFdCount] = playbackProcessSample($pid);
    echo json_encode([
        'tiers' => $results,
        'lifecycle' => [
            'baselineRssMb' => round($baselineRssKb / 1024, 1),
            'finalRssMb' => round($finalRssKb / 1024, 1),
            'rssDeltaMb' => round(($finalRssKb - $baselineRssKb) / 1024, 1),
            'baselineFdCount' => $baselineFdCount,
            'finalFdCount' => $finalFdCount,
            'fdDelta' => $finalFdCount - $baselineFdCount,
            'serverCpuTicksTotal' => $finalTicks - $baselineTicks,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    proc_terminate($process);
    usleep(100_000);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);
    if (file_exists($logPath)) unlink($logPath);
}
