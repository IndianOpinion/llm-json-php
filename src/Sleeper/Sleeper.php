<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Sleeper;

interface Sleeper
{
    public function sleep(float $seconds): void;
}
