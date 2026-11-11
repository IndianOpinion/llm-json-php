<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Exception;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\Skip;

/**
 * Every route failed or was skipped, or the deadline ran out.
 */
class ChainExhaustedException extends LlmJsonException
{
    public const REASON_FAILURES = 'failures';
    public const REASON_DEADLINE = 'deadline';
    public const REASON_ALL_REFUSED = 'all_refused';

    /**
     * @param list<Attempt> $attempts
     * @param list<Skip>    $skips
     */
    public function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $attempts,
        private readonly array $skips,
        private readonly ?\Throwable $lastError,
    ) {
        parent::__construct($message, 0, $lastError);
    }

    /** One of the REASON_* constants. */
    public function reason(): string
    {
        return $this->reason;
    }

    public function allRefused(): bool
    {
        return $this->reason === self::REASON_ALL_REFUSED;
    }

    public function isDeadline(): bool
    {
        return $this->reason === self::REASON_DEADLINE;
    }

    /** @return list<Attempt> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /** @return list<Skip> */
    public function skips(): array
    {
        return $this->skips;
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }
}
