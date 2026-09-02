<?php

namespace helpers\utils;

final class MicQualityMetrics
{
    private const SAMPLE_WINDOW = 256;

    public int $receivedFrames = 0;
    public int $invalidFrames = 0;
    public int $duplicateFrames = 0;
    public int $outOfOrderFrames = 0;
    public int $lateFrames = 0;
    public int $lateFramesDropped = 0;
    public int $serverDroppedFrames = 0;
    public int $pacerUnderruns = 0;
    public int $rtpPacketsSent = 0;
    public int $serverJitterBufferPeakFrames = 0;

    private array $jitterSamples = [];
    private array $frameAgeSamples = [];
    private array $pacingGapSamples = [];
    private ?float $lastTransitMs = null;
    private ?float $minimumTransitMs = null;
    private ?float $lastPacedAtMs = null;
    private array $browser = [];

    public function recordArrival(MicUplinkFrame $frame, float $arrivalMs): void
    {
        $this->receivedFrames++;
        $transit = $arrivalMs - $frame->captureTimestampMs;
        if ($this->lastTransitMs !== null) {
            $this->append($this->jitterSamples, abs($transit - $this->lastTransitMs));
        }
        $this->lastTransitMs = $transit;
        $this->minimumTransitMs = $this->minimumTransitMs === null
            ? $transit
            : min($this->minimumTransitMs, $transit);
    }

    public function estimatedAgeMs(MicUplinkFrame $frame, float $nowMs): float
    {
        if ($this->minimumTransitMs === null) {
            return 0.0;
        }
        return max(0.0, $nowMs - $frame->captureTimestampMs - $this->minimumTransitMs);
    }

    public function recordFrameAge(float $ageMs): void
    {
        $this->append($this->frameAgeSamples, max(0.0, $ageMs));
    }

    public function recordPaced(float $nowMs): void
    {
        if ($this->lastPacedAtMs !== null) {
            $this->append($this->pacingGapSamples, max(0.0, $nowMs - $this->lastPacedAtMs));
        }
        $this->lastPacedAtMs = $nowMs;
        $this->rtpPacketsSent++;
    }

    public function mergeBrowser(array $metrics): void
    {
        $allowed = [
            'capturedFrames', 'sentFrames', 'droppedFrames', 'uplinkDroppedOldFrames',
            'browserQueueFrames', 'browserQueueMs', 'browserQueuePeakMs',
            'wsBufferedAmount', 'wsBufferedPeak', 'clippedSamples', 'totalSamples',
            'clippingPercent',
        ];
        foreach ($allowed as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                $this->browser[$key] = max(0, (float)$metrics[$key]);
            }
        }
    }

    public function snapshot(int $bufferFrames, int $frameMs = 20): array
    {
        $jitterP95 = $this->percentile($this->jitterSamples, 0.95);
        $ageP95 = $this->percentile($this->frameAgeSamples, 0.95);
        $pacingAvg = $this->average($this->pacingGapSamples);
        $pacingP95 = $this->percentile($this->pacingGapSamples, 0.95);
        $pacingP99 = $this->percentile($this->pacingGapSamples, 0.99);
        $pacingMax = $this->pacingGapSamples === [] ? 0.0 : max($this->pacingGapSamples);

        $snapshot = array_merge([
            'receivedFrames' => $this->receivedFrames,
            'invalidFrames' => $this->invalidFrames,
            'duplicateFrames' => $this->duplicateFrames,
            'outOfOrderFrames' => $this->outOfOrderFrames,
            'lateFrames' => $this->lateFrames,
            'lateFramesDropped' => $this->lateFramesDropped,
            'serverDroppedFrames' => $this->serverDroppedFrames,
            'serverJitterBufferFrames' => $bufferFrames,
            'serverJitterBufferMs' => $bufferFrames * $frameMs,
            'serverJitterBufferPeakFrames' => $this->serverJitterBufferPeakFrames,
            'pacerUnderruns' => $this->pacerUnderruns,
            'uplinkJitterMs' => round($this->average($this->jitterSamples), 1),
            'uplinkJitterP95' => round($jitterP95, 1),
            'estimatedFrameAgeMs' => round($this->average($this->frameAgeSamples), 1),
            'frameAgeP95' => round($ageP95, 1),
            'rtpPacketsSent' => $this->rtpPacketsSent,
            'rtpPacingGapAvg' => round($pacingAvg, 2),
            'rtpPacingGapP95' => round($pacingP95, 2),
            'rtpPacingGapP99' => round($pacingP99, 2),
            'rtpPacingGapMax' => round($pacingMax, 2),
        ], $this->browser);
        $snapshot['quality'] = self::qualityState($snapshot);
        return $snapshot;
    }

    /** This is an estimated transmission state, not MOS. */
    public static function qualityState(array $m): string
    {
        $jitter = (float)($m['uplinkJitterP95'] ?? $m['uplinkJitterMs'] ?? 0);
        $queue = (float)($m['browserQueueMs'] ?? 0);
        $ws = (float)($m['wsBufferedAmount'] ?? 0);
        $sent = max(1.0, (float)($m['sentFrames'] ?? $m['receivedFrames'] ?? 1));
        $browserDrops = (float)($m['droppedFrames'] ?? 0);
        $serverDrops = max(
            (float)($m['lateFrames'] ?? 0),
            (float)($m['lateFramesDropped'] ?? 0)
        ) + (float)($m['serverDroppedFrames'] ?? 0);
        $drops = max($browserDrops, $serverDrops);
        $dropPercent = 100 * $drops / ($sent + $drops);
        $underruns = (float)($m['pacerUnderruns'] ?? 0);
        $paced = max(1.0, (float)($m['rtpPacketsSent'] ?? $m['sentFrames'] ?? 1));
        $underrunPercent = 100 * $underruns / $paced;

        if ($ws >= 262144 || $queue >= 160 || $dropPercent >= 8 || $underrunPercent >= 8) return 'critical';
        if ($ws >= 98304 || $queue >= 140 || $jitter >= 60 || $dropPercent >= 4 || $underrunPercent >= 4) return 'poor';
        if ($ws >= 32768 || $queue >= 100 || $jitter >= 30 || $dropPercent >= 1 || $underrunPercent >= 1) return 'unstable';
        if ($queue < 60 && $jitter < 15 && $dropPercent < 0.1 && $underrunPercent === 0.0) return 'excellent';
        return 'good';
    }

    private function append(array &$samples, float $value): void
    {
        $samples[] = $value;
        if (count($samples) > self::SAMPLE_WINDOW) array_shift($samples);
    }

    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) return 0.0;
        sort($values, SORT_NUMERIC);
        $index = (int)ceil($percentile * count($values)) - 1;
        return (float)$values[max(0, min(count($values) - 1, $index))];
    }
}
