<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

use IndianOpinion\LlmJson\Exception\AttemptException;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Usage;

/**
 * A response read into one outcome. Content is set only for a good 200.
 *
 * @internal
 */
final class Classified
{
    public function __construct(
        public readonly Outcome $outcome,
        public readonly ?AttemptException $error = null,
        public readonly ?string $content = null,
        public readonly Usage $usage = new Usage(),
        public readonly ?string $responseId = null,
        public readonly ?string $reportedModel = null,
        public readonly ?string $requestId = null,
        public readonly ?string $errorExcerpt = null,
        public readonly ?float $retryAfter = null,
        public readonly bool $formatRejected = false,
    ) {
    }
}
