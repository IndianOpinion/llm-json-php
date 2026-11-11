<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\AttemptException;

/**
 * Read-only record of one HTTP request to one route.
 */
final class Attempt
{
    public function __construct(
        /** Position in the call, from 1. */
        public readonly int $number,
        /** Index of the route in the chain, from 0. */
        public readonly int $routeIndex,
        public readonly string $provider,
        /** The model the route asked for. Cooldowns use this one. */
        public readonly string $model,
        public readonly OutputMode $mode,
        public readonly Outcome $outcome,
        public readonly ?int $httpStatus,
        public readonly int $durationMs,
        public readonly Usage $usage,
        public readonly ?string $responseId = null,
        public readonly ?string $requestId = null,
        /** The model the provider says answered, when it differs or is given. */
        public readonly ?string $reportedModel = null,
        /** Redacted excerpt of an error body (300 characters at most). */
        public readonly ?string $errorExcerpt = null,
        public readonly ?AttemptException $error = null,
        public readonly ?float $retryAfter = null,
        public readonly bool $fenceUnwrapped = false,
        /** "first", or "regeneration" for attempts made by a G8 regeneration. */
        public readonly string $phase = 'first',
        /** True when the route was tried while on cooldown or hold because it was the last. */
        public readonly bool $lastRouteOverride = false,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->outcome === Outcome::Success;
    }
}
