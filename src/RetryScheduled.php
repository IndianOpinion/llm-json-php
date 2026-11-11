<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

final class RetryScheduled
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        /** Number of the retry on this route, from 1. */
        public readonly int $retry,
        public readonly float $delaySeconds,
        public readonly Outcome $after,
    ) {
    }
}
