<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\Guard\GuardOutcome;
use IndianOpinion\LlmJson\OutputMode;
use IndianOpinion\LlmJson\Route;

/**
 * @internal
 */
final class Success
{
    /**
     * @param array<mixed> $parsed data before guard repairs
     */
    public function __construct(
        public readonly Route $route,
        public readonly Attempt $attempt,
        public readonly OutputMode $mode,
        public readonly string $modeReason,
        public readonly GuardOutcome $guard,
        public readonly array $parsed,
        public readonly string $content,
    ) {
    }
}
