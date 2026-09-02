<?php

namespace helpers\utils;

/** Small bounded FIFO that drops the oldest audio instead of adding latency. */
final class RealtimeStreamQueue
{
    private \SplQueue $items;
    private float $durationMs = 0.0;
    private bool $active = true;
    private int $drops = 0;
    private int $peak = 0;

    public function __construct(
        private readonly float $maxDurationMs = 120.0,
        private readonly int $maxPackets = 24,
    ) {
        if ($maxDurationMs <= 0 || $maxPackets <= 0) {
            throw new \InvalidArgumentException('invalid_realtime_queue_limits');
        }
        $this->items = new \SplQueue();
    }

    /** @return int number of old packets dropped by this enqueue */
    public function enqueue(mixed $value, float $durationMs): int
    {
        if (!$this->active) return 1;
        $durationMs = max(0.01, $durationMs);
        $this->items->enqueue(['value' => $value, 'durationMs' => $durationMs]);
        $this->durationMs += $durationMs;
        $dropped = 0;
        while (!$this->items->isEmpty()
            && ($this->items->count() > $this->maxPackets || $this->durationMs > $this->maxDurationMs)) {
            $oldest = $this->items->dequeue();
            $this->durationMs = max(0.0, $this->durationMs - $oldest['durationMs']);
            $dropped++;
        }
        $this->drops += $dropped;
        $this->peak = max($this->peak, $this->items->count());
        return $dropped;
    }

    public function dequeue(): mixed
    {
        if ($this->items->isEmpty()) return null;
        $item = $this->items->dequeue();
        $this->durationMs = max(0.0, $this->durationMs - $item['durationMs']);
        return $item['value'];
    }

    public function close(): void
    {
        $this->active = false;
        $this->clear();
    }

    public function clear(): void
    {
        $this->items = new \SplQueue();
        $this->durationMs = 0.0;
    }

    public function isActive(): bool { return $this->active; }
    public function isEmpty(): bool { return $this->items->isEmpty(); }
    public function depth(): int { return $this->items->count(); }
    public function durationMs(): float { return $this->durationMs; }
    public function drops(): int { return $this->drops; }
    public function peak(): int { return $this->peak; }
}
