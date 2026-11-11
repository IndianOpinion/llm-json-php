<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * Health of one route, for a status page or CLI.
 */
final class RouteStatus
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $failureCount,
        public readonly ?int $cooldownUntil,
        public readonly ?int $holdUntil,
        public readonly bool $downgradedToJson,
    ) {
    }
}
