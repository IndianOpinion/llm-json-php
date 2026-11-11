<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

/**
 * Retry delays with full-range jitter.
 *
 * @internal
 */
final class Backoff
{
    /**
     * Delay before retry $n (from 1): random in [base * 2^(n-1), 2 * base * 2^(n-1)], capped.
     */
    public static function delay(int $n, float $base, float $cap): float
    {
        $low = $base * (2 ** max(0, $n - 1));
        $value = $low + $low * self::unit();

        return min($value, $cap);
    }

    /** Retry-After plus up to one second of jitter, capped. */
    public static function fromRetryAfter(float $retryAfter, float $cap): float
    {
        return min(max(0.0, $retryAfter) + self::unit(), $cap);
    }

    private static function unit(): float
    {
        return random_int(0, 1_000_000) / 1_000_000;
    }
}
