<?php

namespace helpers\utils;

/** Per-stream aggregate metrics; samples are bounded to avoid diagnostic leaks. */
final class AudioPipelineMetrics
{
    private const MAX_SAMPLES = 512;
    private const SAMPLE_EVERY = 4;

    private int $ipcPackets = 0;
    private int $ipcBytes = 0;
    private int $ipcDrops = 0;
    private int $ipcQueuePeak = 0;
    private array $samples = [
        'receiveGapsUs' => [],
        'processingUs' => [],
        'queueDelayUs' => [],
        'latencyUs' => [],
        'streamProcessingUs' => [],
    ];
    private array $sampleCursors = [];
    private array $observationCounters = [];
    private array $resampleRoutes = [];
    private int $sourceBufferDrops = 0;

    public function recordIpcPacket(int $bytes, ?float $receiveGapUs = null, ?float $latencyUs = null): void
    {
        $this->ipcPackets++;
        $this->ipcBytes += max(0, $bytes);
        if (($this->ipcPackets % self::SAMPLE_EVERY) === 0) {
            if ($receiveGapUs !== null) $this->sample('receiveGapsUs', $receiveGapUs);
            if ($latencyUs !== null && $latencyUs >= 0) $this->sample('latencyUs', $latencyUs);
        }
    }

    public function recordIpcProcessing(float $microseconds): void { $this->sampleObservation('processingUs', $microseconds); }
    public function recordQueueDelay(float $microseconds): void { $this->sampleObservation('queueDelayUs', $microseconds); }
    public function recordStreamProcessing(float $microseconds): void { $this->sampleObservation('streamProcessingUs', $microseconds); }
    public function recordSourceBufferDrop(): void { $this->sourceBufferDrops++; }
    public function recordQueue(int $depth, int $drops = 0): void
    {
        $this->ipcQueuePeak = max($this->ipcQueuePeak, $depth);
        $this->ipcDrops += max(0, $drops);
    }

    public function recordResample(int $inputRate, int $outputRate, int $bytes, float $timeUs): void
    {
        if ($inputRate === $outputRate) return;
        $key = "{$inputRate}->{$outputRate}";
        $this->resampleRoutes[$key] ??= [
            'resampleInputRate' => $inputRate,
            'resampleOutputRate' => $outputRate,
            'resampleCalls' => 0,
            'resampleBytes' => 0,
            'resampleTimeUs' => 0.0,
        ];
        $this->resampleRoutes[$key]['resampleCalls']++;
        $this->resampleRoutes[$key]['resampleBytes'] += max(0, $bytes);
        $this->resampleRoutes[$key]['resampleTimeUs'] += max(0.0, $timeUs);
    }

    public function snapshot(int $queueDepth = 0): array
    {
        $resampleCalls = array_sum(array_column($this->resampleRoutes, 'resampleCalls'));
        $resampleBytes = array_sum(array_column($this->resampleRoutes, 'resampleBytes'));
        $resampleTimeUs = array_sum(array_column($this->resampleRoutes, 'resampleTimeUs'));
        return [
            'ipcPackets' => $this->ipcPackets,
            'ipcBytes' => $this->ipcBytes,
            'ipcReceiveGapP95' => self::percentile($this->samples['receiveGapsUs'], 95),
            'ipcReceiveGapP99' => self::percentile($this->samples['receiveGapsUs'], 99),
            'ipcProcessingUsP95' => self::percentile($this->samples['processingUs'], 95),
            'ipcProcessingUsP99' => self::percentile($this->samples['processingUs'], 99),
            'ipcLatencyUsP95' => self::percentile($this->samples['latencyUs'], 95),
            'ipcLatencyUsP99' => self::percentile($this->samples['latencyUs'], 99),
            'ipcQueueDepth' => $queueDepth,
            'ipcQueuePeak' => $this->ipcQueuePeak,
            'ipcQueueDelayUsP95' => self::percentile($this->samples['queueDelayUs'], 95),
            'ipcQueueDelayUsP99' => self::percentile($this->samples['queueDelayUs'], 99),
            'ipcDrops' => $this->ipcDrops,
            'sourceBufferDrops' => $this->sourceBufferDrops,
            'streamProcessingUsP95' => self::percentile($this->samples['streamProcessingUs'], 95),
            'streamProcessingUsP99' => self::percentile($this->samples['streamProcessingUs'], 99),
            'resampleCalls' => $resampleCalls,
            'resampleBytes' => $resampleBytes,
            'resampleTimeUs' => round($resampleTimeUs, 1),
            'resampleRoutes' => array_values($this->resampleRoutes),
        ];
    }

    private function sample(string $series, float $value): void
    {
        $value = max(0.0, $value);
        if (count($this->samples[$series]) < self::MAX_SAMPLES) {
            $this->samples[$series][] = $value;
            return;
        }

        // Fixed-size ring: array_shift() here used to copy 2,047 values for
        // every frame after warm-up, turning diagnostics into a hot-path cost.
        $cursor = (int)($this->sampleCursors[$series] ?? 0);
        $this->samples[$series][$cursor] = $value;
        $this->sampleCursors[$series] = ($cursor + 1) % self::MAX_SAMPLES;
    }

    private function sampleObservation(string $series, float $value): void
    {
        $count = ($this->observationCounters[$series] ?? 0) + 1;
        $this->observationCounters[$series] = $count;
        if (($count % self::SAMPLE_EVERY) === 0) $this->sample($series, $value);
    }

    private static function percentile(array $values, int $percentile): float
    {
        if ($values === []) return 0.0;
        sort($values, SORT_NUMERIC);
        $index = (int)ceil(($percentile / 100) * count($values)) - 1;
        return round((float)$values[max(0, min(count($values) - 1, $index))], 1);
    }
}
