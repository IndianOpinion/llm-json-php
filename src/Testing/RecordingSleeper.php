<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Testing;

use IndianOpinion\LlmJson\Sleeper\Sleeper;

/**
 * Records every delay instead of sleeping. Given a frozen clock, it moves
 * the clock forward by each delay.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    private array $delays = [];

    public function __construct(private readonly ?FrozenClock $clock = null)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
        $this->clock?->advance($seconds);
    }

    /** @return list<float> */
    public function delays(): array
    {
        return $this->delays;
    }

    public function total(): float
    {
        return array_sum($this->delays);
    }
}
