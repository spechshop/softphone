<?php

namespace helpers\utils;

final class MicUplinkSession
{
    public bool $active = true;
    public bool $started = false;
    public float $lastMetricsAtMs = 0.0;
    public float $lastLogAtMs = 0.0;
    public array $lastBrowserMetrics = [];
    public readonly MicQualityMetrics $metrics;
    public readonly MicJitterBuffer $jitterBuffer;
    public readonly RtpPacer $pacer;

    public function __construct(
        public readonly int $fd,
        public readonly string $stream,
        public readonly string $ssrc,
        public readonly int $sampleRate,
        int $targetMs = 60,
        int $maxFrameAgeMs = 180,
    ) {
        $this->metrics = new MicQualityMetrics();
        $this->jitterBuffer = new MicJitterBuffer($this->metrics, $targetMs, $maxFrameAgeMs);
        $this->pacer = new RtpPacer(MicUplinkFrame::FRAME_MS);
    }

    public function ingest(MicUplinkFrame $frame, float $arrivalMs): bool
    {
        return $this->jitterBuffer->push($frame, $arrivalMs);
    }

    public function startIfReady(float $nowMs): bool
    {
        if (!$this->started && $this->jitterBuffer->isReady($nowMs)) {
            $this->pacer->start($nowMs);
            $this->started = true;
        }
        return $this->started;
    }

    /** Returns PCM16, including encoded-through-normal-pipeline silence on underrun. */
    public function tick(float $nowMs): ?string
    {
        if (!$this->started || !$this->pacer->consumeDeadline($nowMs)) return null;
        $frame = $this->jitterBuffer->pop($nowMs);
        if ($frame === null) {
            $this->metrics->pacerUnderruns++;
            $pcm = str_repeat("\x00\x00", (int)round($this->sampleRate * 0.02));
        } else {
            $pcm = $frame->payload;
        }
        $this->metrics->recordPaced($nowMs);
        return $pcm;
    }

    public function snapshot(): array
    {
        return $this->metrics->snapshot($this->jitterBuffer->count());
    }

    public function close(): void
    {
        $this->active = false;
        $this->jitterBuffer->reset();
        $this->pacer->reset();
    }
}
