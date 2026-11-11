<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Sleeper;

final class RealSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
