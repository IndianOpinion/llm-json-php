<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * One provider reporting that its credit ran out.
 */
final class CreditExhaustion
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $status,
        public readonly string $excerpt,
    ) {
    }
}
