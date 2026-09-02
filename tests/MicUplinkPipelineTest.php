<?php

require_once __DIR__ . '/../plugins/Utils/helpers/MicUplinkFrame.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicQualityMetrics.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicJitterBuffer.php';
require_once __DIR__ . '/../plugins/Utils/helpers/RtpPacer.php';
require_once __DIR__ . '/../plugins/Utils/helpers/MicUplinkSession.php';

use helpers\utils\MicJitterBuffer;
use helpers\utils\MicQualityMetrics;
use helpers\utils\MicUplinkFrame;
use helpers\utils\MicUplinkSession;
use helpers\utils\RtpPacer;

function micAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function micFrame(int $seq, int $captureMs, int $rate = 8000, int $channels = 1, int $frameMs = 20): MicUplinkFrame
{
    $samples = (int)round($rate * ($frameMs / 1000)) * $channels;
    return new MicUplinkFrame(
        $seq,
        $captureMs,
        $rate,
        $samples,
        str_repeat("\x00\x01", $samples),
        flags: ($channels === 2 ? MicUplinkFrame::FLAG_STEREO : 0)
            | ($frameMs === 10 ? MicUplinkFrame::FLAG_FRAME_10MS : 0),
    );
}

// Protocol validation and round trip.
$original = micFrame(0xffffffff, 123456);
$wire = $original->encode();
micAssert(strlen($wire) === 340, 'header/payload size is not 20+320');
$decoded = MicUplinkFrame::decode($wire, 8000, 999);
micAssert($decoded !== null && $decoded->sequence === 0xffffffff, 'sequence did not round trip');
micAssert($decoded->captureTimestampMs === 123456 && $decoded->payload === $original->payload, 'timestamp/payload did not round trip');
micAssert(MicUplinkFrame::decode(substr($wire, 0, -1), 8000) === null, 'truncated payload accepted');
$badMagic = 'XX' . substr($wire, 2);
micAssert(MicUplinkFrame::decode($badMagic, 8000) === null, 'invalid magic accepted');
micAssert(MicUplinkFrame::decode($wire, 16000) === null, 'unexpected sample rate accepted');
$stereoWire = micFrame(1, 20, 48000, 2)->encode();
$stereoDecoded = MicUplinkFrame::decode($stereoWire, 48000);
micAssert($stereoDecoded?->channels() === 2, 'stereo flag did not round trip');
micAssert($stereoDecoded?->samples === 1920 && strlen($stereoDecoded->payload) === 3840, 'stereo interleaved frame size incorrect');
$invalidStereo = substr_replace($stereoWire, pack('n', 960), 14, 2);
micAssert(MicUplinkFrame::decode($invalidStereo, 48000) === null, 'stereo payload accepted with mono sample count');
$tenMs = MicUplinkFrame::decode(micFrame(2, 30, 48000, 2, 10)->encode(), 48000);
micAssert($tenMs?->frameMs() === 10 && $tenMs->samples === 960, '10ms stereo frame validation failed');

// Ordering, duplicate, out-of-order and bounded overflow.
$metrics = new MicQualityMetrics();
$jitter = new MicJitterBuffer($metrics, targetMs: 60, maxFrameAgeMs: 1000, maxFrames: 10);
micAssert($jitter->push(micFrame(10, 0), 1000), 'first frame rejected');
micAssert($jitter->push(micFrame(12, 40), 1040), 'future frame rejected');
micAssert(!$jitter->isReady(1040), 'target started before three frames');
micAssert($jitter->push(micFrame(11, 20), 1020), 'reordered frame rejected');
micAssert(!$jitter->push(micFrame(11, 20), 1021), 'duplicate accepted');
micAssert($metrics->outOfOrderFrames === 1 && $metrics->duplicateFrames === 1, 'sequence metrics incorrect');
micAssert($jitter->isReady(1040), 'three-frame target not enforced');
micAssert($jitter->pop(1060)?->sequence === 10, 'first ordered pop incorrect');
micAssert($jitter->pop(1080)?->sequence === 11, 'second ordered pop incorrect');
micAssert($jitter->pop(1100)?->sequence === 12, 'third ordered pop incorrect');

$overflowMetrics = new MicQualityMetrics();
$overflow = new MicJitterBuffer($overflowMetrics, targetMs: 0, maxFrameAgeMs: 1000, maxFrames: 4);
for ($i = 0; $i < 7; $i++) $overflow->push(micFrame($i, $i * 20), 1000 + $i * 20);
micAssert($overflow->count() === 4 && $overflowMetrics->serverDroppedFrames === 3, 'server queue is not bounded');

$initialReorderMetrics = new MicQualityMetrics();
$initialReorder = new MicJitterBuffer($initialReorderMetrics, targetMs: 0, maxFrameAgeMs: 1000);
$initialReorder->push(micFrame(12, 40), 1040);
$initialReorder->push(micFrame(10, 0), 1041);
$initialReorder->push(micFrame(11, 20), 1042);
micAssert($initialReorder->pop(1042)?->sequence === 10, 'initial out-of-order burst was not sorted');

$wrapMetrics = new MicQualityMetrics();
$wrap = new MicJitterBuffer($wrapMetrics, targetMs: 0, maxFrameAgeMs: 1000);
$wrap->push(micFrame(0, 40), 1042);
$wrap->push(micFrame(0xffffffff, 20), 1041);
$wrap->push(micFrame(0xfffffffe, 0), 1040);
micAssert($wrap->pop(1042)?->sequence === 0xfffffffe, 'sequence wrap ordering failed at max-1');
micAssert($wrap->pop(1062)?->sequence === 0xffffffff, 'sequence wrap ordering failed at max');
micAssert($wrap->pop(1082)?->sequence === 0, 'sequence wrap ordering failed at zero');

// Late frame expiry.
$lateMetrics = new MicQualityMetrics();
$late = new MicJitterBuffer($lateMetrics, targetMs: 0, maxFrameAgeMs: 180);
$late->push(micFrame(1, 0), 1000);
$late->push(micFrame(2, 20), 1020);
micAssert($late->pop(1201) === null && $lateMetrics->lateFramesDropped === 2, 'expired audio was not dropped');

// Deadline pacer: a burst never yields more than one packet per tick/deadline.
$session = new MicUplinkSession(1, 'call', 'mic-test', 8000, targetMs: 60, maxFrameAgeMs: 1000);
for ($i = 0; $i < 10; $i++) $session->ingest(micFrame($i, $i * 20), 1000);
micAssert($session->startIfReady(1000), 'three-frame session target not enforced');
$emittedAt = [];
for ($now = 1000; $now <= 1180; $now++) {
    if ($session->tick($now) !== null) $emittedAt[] = $now;
    // Calling again at the same instant must never release a second packet.
    micAssert($session->tick($now) === null, 'pacer emitted a same-deadline burst');
}
micAssert(count($emittedAt) === 10, 'burst did not drain as ten paced packets');
$gaps = [];
for ($i = 1; $i < count($emittedAt); $i++) $gaps[] = $emittedAt[$i] - $emittedAt[$i - 1];
micAssert(min($gaps) === 20 && max($gaps) === 20, 'paced burst gap differs from 20ms');

// A delayed scheduler callback emits once and resets the next deadline without catch-up burst.
$pacer = new RtpPacer(20);
$pacer->start(0);
micAssert($pacer->consumeDeadline(0), 'initial deadline missing');
micAssert($pacer->consumeDeadline(100), 'late deadline missing');
micAssert(!$pacer->consumeDeadline(100) && !$pacer->consumeDeadline(119), 'late callback caused catch-up burst');
micAssert($pacer->consumeDeadline(120), 'post-delay deadline incorrect');

// Underrun sends PCM silence through the normal codec path and keeps the clock moving.
$under = new MicUplinkSession(2, 'call', 'mic-test', 8000, targetMs: 0, maxFrameAgeMs: 1000);
$under->ingest(micFrame(1, 0), 1000);
$under->startIfReady(1000);
micAssert(strlen((string)$under->tick(1000)) === 320, 'audio frame size incorrect');
$silence = $under->tick(1020);
micAssert($silence === str_repeat("\x00\x00", 160), 'underrun is not PCM16 silence');
micAssert($under->metrics->pacerUnderruns === 1, 'underrun metric missing');
$stereoUnder = new MicUplinkSession(3, 'call', 'mic-stereo', 48000, targetMs: 0, maxFrameAgeMs: 1000, channels: 2);
$stereoUnder->ingest(micFrame(1, 0, 48000, 2), 1000);
$stereoUnder->startIfReady(1000);
micAssert(strlen((string)$stereoUnder->tick(1000)) === 3840, 'stereo payload was not preserved');
micAssert(strlen((string)$stereoUnder->tick(1020)) === 3840, 'stereo underrun silence has wrong size');
$tenMsSession = new MicUplinkSession(4, 'call', 'mic-10ms', 48000, targetMs: 0, maxFrameAgeMs: 1000, channels: 1, frameMs: 10);
$tenMsSession->ingest(micFrame(1, 0, 48000, 1, 10), 1000);
$tenMsSession->startIfReady(1000);
micAssert(strlen((string)$tenMsSession->tick(1000)) === 960, '10ms PCM frame size incorrect');
micAssert(strlen((string)$tenMsSession->tick(1010)) === 960, '10ms underrun size incorrect');

// Estimated quality labels (not MOS).
micAssert(MicQualityMetrics::qualityState(['uplinkJitterP95' => 8, 'browserQueueMs' => 40]) === 'excellent', 'excellent state incorrect');
micAssert(MicQualityMetrics::qualityState(['uplinkJitterP95' => 20, 'browserQueueMs' => 70]) === 'good', 'good state incorrect');
micAssert(MicQualityMetrics::qualityState(['uplinkJitterP95' => 35]) === 'unstable', 'unstable state incorrect');
micAssert(MicQualityMetrics::qualityState(['uplinkJitterP95' => 65]) === 'poor', 'poor state incorrect');
micAssert(MicQualityMetrics::qualityState(['wsBufferedAmount' => 300000]) === 'critical', 'critical state incorrect');
micAssert(MicQualityMetrics::qualityState([
    'recentJitterP95' => 10,
    'recentDropPercent' => 0,
    'totalDropPercent' => 5.4,
    'browserQueueMs' => 0,
    'wsBufferedAmount' => 1024,
    'recentUnderrunPercent' => 0,
    'pacerUnderruns' => 50,
]) === 'excellent', 'accumulated drops incorrectly affect current quality');

$recentJitterMetrics = new MicQualityMetrics();
$recentJitterMetrics->recordArrival(micFrame(1, 0), 1000);
$recentJitterMetrics->recordArrival(micFrame(2, 20), 1030);
micAssert($recentJitterMetrics->snapshot(0, 20, 1030)['recentJitterP95'] === 10.0, 'recent jitter was not recorded');
micAssert($recentJitterMetrics->snapshot(0, 20, 11031)['recentJitterP95'] === 0.0, 'old jitter did not leave the recent window');

// High initial loss ages out of the recent window, but remains in total/cause diagnostics.
$recoveryMetrics = new MicQualityMetrics();
$browserCounters = [
    'capturedFrames' => 10000,
    'sentFrames' => 9460,
    'droppedFrames' => 540,
    'uplinkDroppedOldFrames' => 540,
];
$recoveryMetrics->mergeBrowser($browserCounters);
$recoveryMetrics->lateFrames = 6;
$recoveryMetrics->lateFramesDropped = 2;
$recoveryMetrics->serverDroppedFrames = 3;
$badStart = $recoveryMetrics->snapshot(0, 20, 0);
micAssert($badStart['quality'] === 'poor', 'high initial loss was not classified as poor');
micAssert($badStart['browserDrops'] === 540.0, 'browser drop cause incorrect');
micAssert($badStart['serverLateDrops'] === 2.0, 'server late drop cause incorrect');
micAssert($badStart['serverOverflowDrops'] === 3.0, 'server overflow cause incorrect');
micAssert($badStart['sequenceGaps'] === 4.0, 'sequence gap cause incorrect');

$recovered = [];
for ($second = 1; $second <= 10; $second++) {
    $browserCounters['capturedFrames'] += 100;
    $browserCounters['sentFrames'] += 100;
    $recoveryMetrics->mergeBrowser($browserCounters);
    $recovered = $recoveryMetrics->snapshot(0, 20, $second * 1000);
}
micAssert($recovered['recentDropPercent'] === 0.0, 'recent drops did not recover to zero');
micAssert($recovered['totalDropPercent'] > 4.0, 'total drops did not preserve call history');
micAssert($recovered['quality'] === 'excellent', 'quality did not recover after the rolling window');

// Continuous 5% loss remains poor in the recent window.
$persistentMetrics = new MicQualityMetrics();
$persistentCounters = ['capturedFrames' => 10, 'sentFrames' => 0, 'droppedFrames' => 0];
$persistentMetrics->mergeBrowser($persistentCounters);
$persistentMetrics->snapshot(0, 20, 0);
$persistent = [];
for ($second = 1; $second <= 10; $second++) {
    $persistentCounters['capturedFrames'] += 100;
    $persistentCounters['sentFrames'] += 95;
    $persistentCounters['droppedFrames'] += 5;
    $persistentMetrics->mergeBrowser($persistentCounters);
    $persistent = $persistentMetrics->snapshot(0, 20, $second * 1000);
}
micAssert(abs($persistent['recentDropPercent'] - 5.0) < 0.001, 'continuous recent loss percentage incorrect');
micAssert($persistent['quality'] === 'poor', 'continuous real loss did not remain poor');

// A new call owns a fresh metrics object and cannot inherit the prior window.
$newCallMetrics = new MicQualityMetrics();
$newCall = $newCallMetrics->snapshot(0, 20, 11_000);
micAssert($newCall['recentDropPercent'] === 0.0 && $newCall['totalDropPercent'] === 0.0, 'metrics leaked between calls');
micAssert($newCall['quality'] === 'excellent', 'new server-side call did not reset quality');

$snapshot = $session->snapshot(1180);
micAssert($snapshot['rtpPacingGapAvg'] === 20.0 && $snapshot['rtpPacingGapP95'] === 20.0, 'pacing metrics incorrect');
$session->close();
micAssert(!$session->active && $session->jitterBuffer->count() === 0 && $session->pacer->deadlineMs() === null, 'session cleanup failed');

echo "OK: protocolo, ordering, duplicate, late/drop, bounded queue, pacing, underrun, quality e cleanup.\n";
echo 'BURST: arrival=10x0ms output_gaps=' . implode(',', $gaps)
    . " avg={$snapshot['rtpPacingGapAvg']}ms p95={$snapshot['rtpPacingGapP95']}ms"
    . " p99={$snapshot['rtpPacingGapP99']}ms max={$snapshot['rtpPacingGapMax']}ms\n";
