<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

final class CooldownStarted
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly float $until,
    ) {
    }
}
