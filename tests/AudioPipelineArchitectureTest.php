<?php

declare(strict_types=1);

require_once __DIR__ . '/../plugins/Utils/helpers/AudioPipelineMetrics.php';
require_once __DIR__ . '/../plugins/Utils/helpers/PcmProcessor.php';
require_once __DIR__ . '/../plugins/Utils/helpers/AudioIpcPacket.php';
require_once __DIR__ . '/../plugins/Utils/helpers/RealtimeStreamQueue.php';

use helpers\utils\AudioIpcPacket;
use helpers\utils\AudioPipelineMetrics;
use helpers\utils\PcmProcessor;
use helpers\utils\RealtimeStreamQueue;

function pipelineAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function pipelinePcm(int $rate, int $channels = 1, int $milliseconds = 20): string
{
    return str_repeat("\x01\x00", (int)round($rate * $milliseconds / 1000) * $channels);
}

// Compatible rates are strict no-ops: the native resampler must not be called.
foreach ([8000, 16000, 24000, 48000] as $rate) {
    $calls = 0;
    $pcm = pipelinePcm($rate);
    $output = PcmProcessor::convert(
        $pcm,
        $rate,
        1,
        $rate,
        1,
        null,
        static function () use (&$calls): string {
            $calls++;
            throw new RuntimeException('equal-rate resampler call');
        },
    );
    pipelineAssert($output === $pcm, "{$rate} -> {$rate} changed PCM");
    pipelineAssert($calls === 0, "{$rate} -> {$rate} resampled");
}

// Every required mono conversion is one direct logical/native call.
foreach ([[8000, 48000], [48000, 8000], [24000, 48000]] as [$from, $to]) {
    $calls = [];
    $metrics = new AudioPipelineMetrics();
    PcmProcessor::convert(
        pipelinePcm($from),
        $from,
        1,
        $to,
        1,
        $metrics,
        static function (string $pcm, int $source, int $target) use (&$calls): string {
            $calls[] = [$source, $target];
            return $pcm;
        },
    );
    pipelineAssert($calls === [[$from, $to]], "{$from} -> {$to} was not direct");
    $snapshot = $metrics->snapshot();
    pipelineAssert($snapshot['resampleCalls'] === 1, "{$from} -> {$to} metric count");
    pipelineAssert(
        ($snapshot['resampleRoutes'][0]['resampleInputRate'] ?? 0) === $from
        && ($snapshot['resampleRoutes'][0]['resampleOutputRate'] ?? 0) === $to,
        "{$from} -> {$to} metric route",
    );
}

// Stereo uses two native planes but remains one logical direct conversion.
$stereoCalls = [];
$stereoMetrics = new AudioPipelineMetrics();
PcmProcessor::convert(
    pipelinePcm(24000, 2),
    24000,
    2,
    48000,
    2,
    $stereoMetrics,
    static function (string $pcm, int $source, int $target) use (&$stereoCalls): string {
        $stereoCalls[] = [$source, $target];
        return $pcm;
    },
);
pipelineAssert($stereoCalls === [[24000, 48000], [24000, 48000]], 'stereo planes used an intermediate rate');
pipelineAssert($stereoMetrics->snapshot()['resampleCalls'] === 1, 'stereo conversion was not one logical resample');

// Binary IPC is payload-safe and transports the original PCM format.
$payload = "\x00\x00__::__\xff\x10\x00\x00";
$packet = new AudioIpcPacket($payload, 'call__::__42', 'mic-device', 48000, 2, 65535, 123456789);
$encoded = $packet->encode();
$decoded = AudioIpcPacket::decode($encoded);
pipelineAssert($decoded instanceof AudioIpcPacket, 'binary IPC did not decode');
pipelineAssert($decoded->payload === $payload, 'binary IPC changed payload');
pipelineAssert($decoded->stream === 'call__::__42', 'binary IPC changed stream');
pipelineAssert($decoded->sampleRate === 48000 && $decoded->channels === 2, 'binary IPC changed PCM format');
pipelineAssert($decoded->replyPort === 65535 && $decoded->sentAtNs === 123456789, 'binary IPC changed metadata');

$legacy = AudioIpcPacket::decodeLegacyPlayback("pcm__::__call__::__source__::__9000__::__8000__::__16000__::__1");
pipelineAssert($legacy?->sampleRate === 16000 && $legacy->channels === 1, 'legacy playback compatibility');
$legacyCapture = AudioIpcPacket::decodeLegacyCapture('pcm!', 24000, 2);
pipelineAssert($legacyCapture?->sampleRate === 24000 && $legacyCapture->channels === 2, 'legacy capture compatibility');

// Queue limits are time-based and discard the oldest audio.
$queue = new RealtimeStreamQueue(40.0, 100);
pipelineAssert($queue->enqueue('old', 20.0) === 0, 'first enqueue dropped');
pipelineAssert($queue->enqueue('middle', 20.0) === 0, 'second enqueue dropped');
pipelineAssert($queue->enqueue('new', 20.0) === 1, 'queue did not discard oldest audio');
pipelineAssert($queue->durationMs() === 40.0 && $queue->depth() === 2, 'queue exceeded time budget');
pipelineAssert($queue->dequeue() === 'middle' && $queue->dequeue() === 'new', 'queue did not retain newest audio');
$queue->close();
pipelineAssert(!$queue->isActive() && $queue->depth() === 0, 'queue lifecycle cleanup failed');

// Source-buffer drops must not be reported as IPC queue drops.
$metrics = new AudioPipelineMetrics();
$metrics->recordQueue(2, 1);
$metrics->recordSourceBufferDrop();
$snapshot = $metrics->snapshot(2);
pipelineAssert($snapshot['ipcDrops'] === 1, 'IPC drop metric contaminated');
pipelineAssert($snapshot['sourceBufferDrops'] === 1, 'source-buffer drop metric missing');

// Guard the architectural invariants at both browser/media bridges.
foreach ([
    __DIR__ . '/../plugins/Message/handlers/acceptCall.php',
    __DIR__ . '/../plugins/Utils/helpers/OutboundMediaSession.php',
] as $bridgeFile) {
    $source = (string)file_get_contents($bridgeFile);
    pipelineAssert(!str_contains($source, 'OpusConfig::resamplePcm'), basename($bridgeFile) . ' pre-resamples Opus');
    pipelineAssert(!str_contains($source, 'explode(\'__::__\', $raw'), basename($bridgeFile) . ' parses text IPC in hot path');
    pipelineAssert(str_contains($source, 'AudioIpcPacket::decode($raw)'), basename($bridgeFile) . ' does not decode binary IPC');
}

echo "OK: PCM direct-resample, binary IPC, bounded queue, metrics and bridge invariants.\n";
