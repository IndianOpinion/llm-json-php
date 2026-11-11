<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
