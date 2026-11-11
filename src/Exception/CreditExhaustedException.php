<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Result;
use IndianOpinion\LlmJson\Skip;

/**
 * At least one provider ran out of credit. Thrown when the chain failed with
 * a credit problem on the way, or, in strict credit mode, even when a later
 * provider succeeded; the result is then attached.
 */
final class CreditExhaustedException extends ChainExhaustedException
{
    /**
     * @param list<Attempt>          $attempts
     * @param list<Skip>             $skips
     * @param list<CreditExhaustion> $providers
     */
    public function __construct(
        string $message,
        string $reason,
        array $attempts,
        array $skips,
        ?\Throwable $lastError,
        private readonly array $providers,
        private readonly ?Result $result = null,
    ) {
        parent::__construct($message, $reason, $attempts, $skips, $lastError);
    }

    /** @return list<CreditExhaustion> */
    public function exhaustedProviders(): array
    {
        return $this->providers;
    }

    /** The successful result, in strict credit mode only. */
    public function result(): ?Result
    {
        return $this->result;
    }
}
