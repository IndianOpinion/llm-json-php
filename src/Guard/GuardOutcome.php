<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

/**
 * The guard's verdict on one parsed output.
 *
 * @internal
 */
final class GuardOutcome
{
    /**
     * @param array<mixed>    $data
     * @param list<Repair>    $repairs
     * @param list<Violation> $violations
     * @param list<string>    $rulesRun
     */
    public function __construct(
        public readonly array $data,
        public readonly array $repairs,
        public readonly array $violations,
        public readonly array $rulesRun,
        public readonly bool $floorShortfall,
        public readonly int $floorWords,
    ) {
    }

    public function passed(): bool
    {
        return $this->violations === [];
    }
}
