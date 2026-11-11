<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Guard;

/**
 * What the guard did on a call: rules run, repairs on the returned data,
 * rejections on earlier attempts, and whether a regeneration happened.
 */
final class GuardReport
{
    /**
     * @param list<string>    $rulesRun
     * @param list<Repair>    $repairs
     * @param list<Violation> $earlierRejections
     * @param string|null     $kept "first" or "regenerated" when a regeneration ran
     */
    public function __construct(
        public readonly array $rulesRun,
        public readonly array $repairs,
        public readonly array $earlierRejections,
        public readonly bool $regenerated = false,
        public readonly ?string $kept = null,
        public readonly bool $regenerationFailed = false,
    ) {
    }

    public function repaired(): bool
    {
        return $this->repairs !== [];
    }
}
