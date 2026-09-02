<?php

namespace helpers\utils;

/** Per-stream aggregate metrics; samples are bounded to avoid diagnostic leaks. */
final class AudioPipelineMetrics
{
    private const MAX_SAMPLES = 2048;

    private int $ipcPackets = 0;
    private int $ipcBytes = 0;
    private int $ipcDrops = 0;
    private int $ipcQueuePeak = 0;
    private array $receiveGapsUs = [];
    private array $processingUs = [];
    private array $queueDelayUs = [];
    private array $latencyUs = [];
    private array $resampleRoutes = [];
    private array $streamProcessingUs = [];

    public function recordIpcPacket(int $bytes, ?float $receiveGapUs = null, ?float $latencyUs = null): void
    {
        $this->ipcPackets++;
        $this->ipcBytes += max(0, $bytes);
        if ($receiveGapUs !== null) $this->sample($this->receiveGapsUs, $receiveGapUs);
        if ($latencyUs !== null && $latencyUs >= 0) $this->sample($this->latencyUs, $latencyUs);
    }

    public function recordIpcProcessing(float $microseconds): void { $this->sample($this->processingUs, $microseconds); }
    public function recordQueueDelay(float $microseconds): void { $this->sample($this->queueDelayUs, $microseconds); }
    public function recordStreamProcessing(float $microseconds): void { $this->sample($this->streamProcessingUs, $microseconds); }
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
            'ipcReceiveGapP95' => self::percentile($this->receiveGapsUs, 95),
            'ipcReceiveGapP99' => self::percentile($this->receiveGapsUs, 99),
            'ipcProcessingUsP95' => self::percentile($this->processingUs, 95),
            'ipcProcessingUsP99' => self::percentile($this->processingUs, 99),
            'ipcLatencyUsP95' => self::percentile($this->latencyUs, 95),
            'ipcLatencyUsP99' => self::percentile($this->latencyUs, 99),
            'ipcQueueDepth' => $queueDepth,
            'ipcQueuePeak' => $this->ipcQueuePeak,
            'ipcQueueDelayUsP95' => self::percentile($this->queueDelayUs, 95),
            'ipcQueueDelayUsP99' => self::percentile($this->queueDelayUs, 99),
            'ipcDrops' => $this->ipcDrops,
            'streamProcessingUsP95' => self::percentile($this->streamProcessingUs, 95),
            'streamProcessingUsP99' => self::percentile($this->streamProcessingUs, 99),
            'resampleCalls' => $resampleCalls,
            'resampleBytes' => $resampleBytes,
            'resampleTimeUs' => round($resampleTimeUs, 1),
            'resampleRoutes' => array_values($this->resampleRoutes),
        ];
    }

    private function sample(array &$samples, float $value): void
    {
        if (count($samples) >= self::MAX_SAMPLES) array_shift($samples);
        $samples[] = max(0.0, $value);
    }

    private static function percentile(array $values, int $percentile): float
    {
        if ($values === []) return 0.0;
        sort($values, SORT_NUMERIC);
        $index = (int)ceil(($percentile / 100) * count($values)) - 1;
        return round((float)$values[max(0, min(count($values) - 1, $index))], 1);
    }
}
