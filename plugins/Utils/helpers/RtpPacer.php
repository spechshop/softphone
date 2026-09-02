<?php

namespace helpers\utils;

/** Deadline-based pacer. tick() can release at most one packet. */
final class RtpPacer
{
    private ?float $nextDeadlineMs = null;

    public function __construct(public readonly int $packetTimeMs = 20)
    {
        if ($packetTimeMs < 1) throw new \InvalidArgumentException('packetTimeMs must be positive');
    }

    public function start(float $nowMs): void { $this->nextDeadlineMs = $nowMs; }
    public function reset(): void { $this->nextDeadlineMs = null; }
    public function deadlineMs(): ?float { return $this->nextDeadlineMs; }

    public function isDue(float $nowMs): bool
    {
        return $this->nextDeadlineMs !== null && $nowMs >= $this->nextDeadlineMs;
    }

    public function consumeDeadline(float $nowMs): bool
    {
        if (!$this->isDue($nowMs)) return false;
        $scheduled = $this->nextDeadlineMs;
        $candidate = $scheduled + $this->packetTimeMs;
        // Preserve the monotonic timeline after ordinary sub-ptime scheduler
        // jitter. Only a whole missed slot resets from now, preventing catch-up.
        $this->nextDeadlineMs = $candidate > $nowMs
            ? $candidate
            : $nowMs + $this->packetTimeMs;
        return true;
    }
}
