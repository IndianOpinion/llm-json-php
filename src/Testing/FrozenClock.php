<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Testing;

use IndianOpinion\LlmJson\Clock\Clock;

/**
 * A clock that moves only when told to.
 */
final class FrozenClock implements Clock
{
    public function __construct(private float $now = 1_767_225_600.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(float $now): void
    {
        $this->now = $now;
    }
}
