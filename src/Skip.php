<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * A route that was not attempted, and why.
 */
final class Skip
{
    public const COOLDOWN = 'cooldown';
    public const HOLD = 'credit_hold';
    public const ACCOUNT = 'account_failure_in_call';

    public function __construct(
        public readonly int $routeIndex,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $reason,
        public readonly ?float $until = null,
    ) {
    }
}
