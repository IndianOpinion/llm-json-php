<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Clock;

interface Clock
{
    /** Unix time in seconds, with fractions. */
    public function now(): float;
}
