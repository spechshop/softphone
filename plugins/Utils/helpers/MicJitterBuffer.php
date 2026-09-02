<?php

namespace helpers\utils;

final class MicJitterBuffer
{
    private array $frames = [];
    private array $seen = [];
    private ?int $expectedSequence = null;
    private ?int $highestSequence = null;
    private ?float $firstArrivalMs = null;

    public function __construct(
        private readonly MicQualityMetrics $metrics,
        public readonly int $targetMs = 60,
        public readonly int $maxFrameAgeMs = 180,
        public readonly int $maxFrames = 10,
    ) {
    }

    public function push(MicUplinkFrame $frame, float $arrivalMs): bool
    {
        $seq = $frame->sequence;
        if (isset($this->seen[$seq])) {
            $this->metrics->duplicateFrames++;
            return false;
        }
        if ($this->expectedSequence !== null && self::sequenceBefore($seq, $this->expectedSequence)) {
            $this->metrics->lateFrames++;
            $this->metrics->lateFramesDropped++;
            return false;
        }
        if ($this->highestSequence !== null && self::sequenceBefore($seq, $this->highestSequence)) {
            $this->metrics->outOfOrderFrames++;
        }

        $this->seen[$seq] = true;
        if (count($this->seen) > 512) unset($this->seen[array_key_first($this->seen)]);
        $this->highestSequence = $this->highestSequence === null || self::sequenceBefore($this->highestSequence, $seq)
            ? $seq : $this->highestSequence;
        $this->firstArrivalMs ??= $arrivalMs;
        $this->frames[$seq] = $frame;
        $this->metrics->recordArrival($frame, $arrivalMs);

        while (count($this->frames) > $this->maxFrames) {
            $oldest = $this->oldestSequence();
            unset($this->frames[$oldest]);
            $this->metrics->serverDroppedFrames++;
            $this->expectedSequence = self::nextSequence($oldest);
        }
        $this->metrics->serverJitterBufferPeakFrames = max(
            $this->metrics->serverJitterBufferPeakFrames,
            count($this->frames)
        );
        return true;
    }

    public function isReady(float $nowMs): bool
    {
        return $this->frames !== []
            && $this->firstArrivalMs !== null
            && (count($this->frames) >= max(1, (int)ceil($this->targetMs / MicUplinkFrame::FRAME_MS))
                || ($nowMs - $this->firstArrivalMs) >= $this->targetMs);
    }

    public function pop(float $nowMs): ?MicUplinkFrame
    {
        $this->dropExpired($nowMs);
        if ($this->frames === []) return null;

        if ($this->expectedSequence !== null && isset($this->frames[$this->expectedSequence])) {
            $seq = $this->expectedSequence;
        } else {
            $seq = $this->oldestSequence();
            if ($this->expectedSequence !== null && $seq !== $this->expectedSequence) {
                $distance = self::sequenceDistance($this->expectedSequence, $seq);
                if ($distance > 0 && $distance < 0x80000000) {
                    $this->metrics->lateFrames += $distance;
                }
            }
        }

        $frame = $this->frames[$seq];
        unset($this->frames[$seq]);
        $this->expectedSequence = self::nextSequence($seq);
        $this->metrics->recordFrameAge($this->metrics->estimatedAgeMs($frame, $nowMs));
        return $frame;
    }

    public function count(): int { return count($this->frames); }
    public function reset(): void
    {
        $this->frames = [];
        $this->seen = [];
        $this->expectedSequence = null;
        $this->highestSequence = null;
        $this->firstArrivalMs = null;
    }

    private function dropExpired(float $nowMs): void
    {
        while ($this->frames !== []) {
            $seq = $this->oldestSequence();
            $frame = $this->frames[$seq];
            if ($this->metrics->estimatedAgeMs($frame, $nowMs) <= $this->maxFrameAgeMs) break;
            unset($this->frames[$seq]);
            $this->metrics->lateFrames++;
            $this->metrics->lateFramesDropped++;
            $this->expectedSequence = self::nextSequence($seq);
        }
    }

    private function oldestSequence(): int
    {
        $sequences = array_keys($this->frames);
        if ($this->expectedSequence !== null) {
            $reference = $this->expectedSequence;
            usort($sequences, static fn(int $a, int $b): int =>
                self::sequenceDistance($reference, $a) <=> self::sequenceDistance($reference, $b));
        } else {
            usort($sequences, static function (int $a, int $b): int {
                if ($a === $b) return 0;
                return self::sequenceBefore($a, $b) ? -1 : 1;
            });
        }
        return $sequences[0];
    }

    private static function nextSequence(int $sequence): int { return ($sequence + 1) & 0xffffffff; }
    private static function sequenceDistance(int $from, int $to): int { return ($to - $from) & 0xffffffff; }
    private static function sequenceBefore(int $a, int $b): bool
    {
        $distance = self::sequenceDistance($a, $b);
        return $distance > 0 && $distance < 0x80000000;
    }
}
