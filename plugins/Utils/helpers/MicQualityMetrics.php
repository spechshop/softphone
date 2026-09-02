<?php

namespace helpers\utils;

final class MicQualityMetrics
{
    private const SAMPLE_WINDOW = 256;
    private const RECENT_WINDOW_MS = 10_000;

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
    private array $recentJitterTimes = [];
    private array $recentJitterValues = [];
    private int $recentJitterStart = 0;
    private array $frameAgeSamples = [];
    private array $pacingGapSamples = [];
    private ?float $lastTransitMs = null;
    private ?float $minimumTransitMs = null;
    private ?float $lastPacedAtMs = null;
    private array $browser = [];
    private array $counterSamples = [];

    public function recordArrival(MicUplinkFrame $frame, float $arrivalMs): void
    {
        $this->receivedFrames++;
        $transit = $arrivalMs - $frame->captureTimestampMs;
        if ($this->lastTransitMs !== null) {
            $jitter = abs($transit - $this->lastTransitMs);
            $this->append($this->jitterSamples, $jitter);
            $this->recentJitterTimes[] = $arrivalMs;
            $this->recentJitterValues[] = $jitter;
            $this->trimRecentJitter($arrivalMs);
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

    public function snapshot(int $bufferFrames, int $frameMs = 20, ?float $nowMs = null): array
    {
        $nowMs ??= hrtime(true) / 1_000_000;
        $jitterP95 = $this->percentile($this->jitterSamples, 0.95);
        $recentJitterP95 = $this->recentJitterP95($nowMs);
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
            'recentJitterP95' => round($recentJitterP95, 1),
            'estimatedFrameAgeMs' => round($this->average($this->frameAgeSamples), 1),
            'frameAgeP95' => round($ageP95, 1),
            'rtpPacketsSent' => $this->rtpPacketsSent,
            'rtpPacingGapAvg' => round($pacingAvg, 2),
            'rtpPacingGapP95' => round($pacingP95, 2),
            'rtpPacingGapP99' => round($pacingP99, 2),
            'rtpPacingGapMax' => round($pacingMax, 2),
        ], $this->browser);
        $counters = self::dropCounters($snapshot);
        $snapshot = array_merge($snapshot, $counters);
        $snapshot['totalDrops'] = self::aggregateDrops($counters);
        $totalSent = max(0.0, (float)($snapshot['sentFrames'] ?? $snapshot['receivedFrames'] ?? 0));
        $snapshot['totalDropPercent'] = self::percent($snapshot['totalDrops'], $totalSent + $snapshot['totalDrops']);

        $recent = $this->recentCounters($nowMs, $counters);
        $snapshot['recentBrowserDrops'] = $recent['browserDrops'];
        $snapshot['recentServerLateDrops'] = $recent['serverLateDrops'];
        $snapshot['recentServerOverflowDrops'] = $recent['serverOverflowDrops'];
        $snapshot['recentSequenceGaps'] = $recent['sequenceGaps'];
        $snapshot['recentDrops'] = self::aggregateDrops($recent);
        $recentSent = $recent['sentFrames'] > 0 ? $recent['sentFrames'] : $recent['receivedFrames'];
        $snapshot['recentDropPercent'] = self::percent($snapshot['recentDrops'], $recentSent + $snapshot['recentDrops']);
        $snapshot['recentUnderruns'] = $recent['pacerUnderruns'];
        $snapshot['recentUnderrunPercent'] = self::percent($recent['pacerUnderruns'], $recent['rtpPacketsSent']);
        $snapshot['dropPercent'] = $snapshot['recentDropPercent'];
        $snapshot['quality'] = self::qualityState($snapshot);
        return $snapshot;
    }

    /** This is an estimated transmission state, not MOS. */
    public static function qualityState(array $m): string
    {
        $jitter = (float)($m['recentJitterP95'] ?? $m['uplinkJitterP95'] ?? $m['uplinkJitterMs'] ?? 0);
        $queue = (float)($m['browserQueueMs'] ?? 0);
        $ws = (float)($m['wsBufferedAmount'] ?? 0);
        $dropPercent = (float)($m['recentDropPercent'] ?? $m['dropPercent'] ?? 0);
        $underrunPercent = (float)($m['recentUnderrunPercent']
            ?? self::percent((float)($m['pacerUnderruns'] ?? 0), (float)($m['rtpPacketsSent'] ?? $m['sentFrames'] ?? 0)));

        if ($ws >= 262144 || $queue >= 160 || $dropPercent >= 8 || $underrunPercent >= 8) return 'critical';
        if ($ws >= 98304 || $queue >= 140 || $jitter >= 60 || $dropPercent >= 4 || $underrunPercent >= 4) return 'poor';
        if ($ws >= 32768 || $queue >= 100 || $jitter >= 30 || $dropPercent >= 1 || $underrunPercent >= 1) return 'unstable';
        if ($queue < 60 && $jitter < 15 && $dropPercent < 0.1 && $underrunPercent === 0.0) return 'excellent';
        return 'good';
    }

    private static function dropCounters(array $m): array
    {
        $serverLateDrops = max(0.0, (float)($m['lateFramesDropped'] ?? 0));
        return [
            'browserDrops' => max(0.0, (float)($m['droppedFrames'] ?? 0), (float)($m['uplinkDroppedOldFrames'] ?? 0)),
            'serverLateDrops' => $serverLateDrops,
            'serverOverflowDrops' => max(0.0, (float)($m['serverDroppedFrames'] ?? 0)),
            'sequenceGaps' => max(0.0, (float)($m['lateFrames'] ?? 0) - $serverLateDrops),
            'sentFrames' => max(0.0, (float)($m['sentFrames'] ?? 0)),
            'receivedFrames' => max(0.0, (float)($m['receivedFrames'] ?? 0)),
            'pacerUnderruns' => max(0.0, (float)($m['pacerUnderruns'] ?? 0)),
            'rtpPacketsSent' => max(0.0, (float)($m['rtpPacketsSent'] ?? 0)),
        ];
    }

    private static function aggregateDrops(array $counters): float
    {
        $serverDrops = $counters['serverLateDrops'] + $counters['serverOverflowDrops'] + $counters['sequenceGaps'];
        return max($counters['browserDrops'], $serverDrops);
    }

    private static function percent(float $numerator, float $denominator): float
    {
        return 100 * max(0.0, $numerator) / max(1.0, $denominator);
    }

    private function recentCounters(float $nowMs, array $counters): array
    {
        $point = array_merge(['at' => $nowMs], $counters);
        $lastIndex = count($this->counterSamples) - 1;
        if ($lastIndex >= 0 && $this->counterSamples[$lastIndex]['at'] === $nowMs) {
            $this->counterSamples[$lastIndex] = $point;
        } else {
            $this->counterSamples[] = $point;
        }

        $cutoff = $nowMs - self::RECENT_WINDOW_MS;
        $baselineIndex = null;
        foreach ($this->counterSamples as $index => $sample) {
            if ($sample['at'] <= $cutoff) $baselineIndex = $index;
            else break;
        }
        $baseline = $baselineIndex === null ? [] : $this->counterSamples[$baselineIndex];
        if ($baselineIndex !== null && $baselineIndex > 0) {
            $this->counterSamples = array_slice($this->counterSamples, $baselineIndex);
        }

        $recent = [];
        foreach (array_keys($counters) as $key) {
            $recent[$key] = max(0.0, $counters[$key] - (float)($baseline[$key] ?? 0));
        }
        return $recent;
    }

    private function recentJitterP95(float $nowMs): float
    {
        $this->trimRecentJitter($nowMs);
        return $this->percentile(
            array_slice($this->recentJitterValues, $this->recentJitterStart),
            0.95
        );
    }

    private function trimRecentJitter(float $nowMs): void
    {
        $cutoff = $nowMs - self::RECENT_WINDOW_MS;
        $count = count($this->recentJitterTimes);
        while ($this->recentJitterStart < $count
            && $this->recentJitterTimes[$this->recentJitterStart] <= $cutoff) {
            $this->recentJitterStart++;
        }
        if ($this->recentJitterStart >= 256 && $this->recentJitterStart * 2 >= $count) {
            $this->recentJitterTimes = array_slice($this->recentJitterTimes, $this->recentJitterStart);
            $this->recentJitterValues = array_slice($this->recentJitterValues, $this->recentJitterStart);
            $this->recentJitterStart = 0;
        }
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
