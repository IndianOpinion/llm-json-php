<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

/**
 * One guard repair. Counts are null where they do not apply.
 */
final class Repair
{
    public function __construct(
        public readonly string $rule,
        public readonly string $path,
        public readonly string $description,
        public readonly ?int $charactersRemoved = null,
        public readonly ?int $wordsBefore = null,
        public readonly ?int $wordsAfter = null,
        public readonly bool $itemRemoved = false,
    ) {
    }
}
