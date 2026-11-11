<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * Client-wide settings. Immutable; every with* method returns a copy.
 * Defaults match the configuration table in the README.
 */
final class Options
{
    private int $attemptsPerRoute = 2;
    private int $defaultTokenCap = 8192;
    private float $retryBaseDelay = 2.0;
    private float $retryMaxDelay = 30.0;
    private float $connectTimeout = 10.0;
    private float $requestTimeout = 90.0;
    private float $deadline = 300.0;
    private float $minUsefulTime = 5.0;
    private int $cooldownThreshold = 2;
    private int $failureWindow = 1800;
    private int $cooldownSeconds = 600;
    private int $creditHoldSeconds = 60;
    private int $logThrottleSeconds = 600;
    private int $downgradeSeconds = 86400;
    private int $maxResponseBytes = 8 * 1024 * 1024;
    private string $storeKeyPrefix = 'llmjson';
    private bool $reliability = true;
    private bool $strictCredit = false;
    private bool $excerptLogging = false;

    public static function defaults(): self
    {
        return new self();
    }

    public function withAttemptsPerRoute(int $attempts): self
    {
        self::atLeast($attempts, 1, 'attempts per route');

        return $this->set('attemptsPerRoute', $attempts);
    }

    public function withDefaultTokenCap(int $cap): self
    {
        self::atLeast($cap, 1, 'token cap');

        return $this->set('defaultTokenCap', $cap);
    }

    public function withRetryDelays(float $baseSeconds, float $maxSeconds): self
    {
        if (!($baseSeconds >= 0) || !($maxSeconds >= $baseSeconds) || !is_finite($maxSeconds)) {
            throw new ConfigurationException('Retry delays need 0 <= base <= max.');
        }
        $clone = clone $this;
        $clone->retryBaseDelay = $baseSeconds;
        $clone->retryMaxDelay = $maxSeconds;

        return $clone;
    }

    public function withTimeouts(float $connectSeconds, float $requestSeconds): self
    {
        if (!($connectSeconds > 0) || !($requestSeconds > 0) || !is_finite($connectSeconds) || !is_finite($requestSeconds)) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }
        $clone = clone $this;
        $clone->connectTimeout = $connectSeconds;
        $clone->requestTimeout = $requestSeconds;

        return $clone;
    }

    public function withDeadline(float $seconds, ?float $minUsefulSeconds = null): self
    {
        if (!($seconds > 0) || ($minUsefulSeconds !== null && !($minUsefulSeconds >= 0))) {
            throw new ConfigurationException('Deadline must be greater than zero and the minimum useful time zero or more.');
        }
        $clone = clone $this;
        $clone->deadline = $seconds;
        if ($minUsefulSeconds !== null) {
            $clone->minUsefulTime = $minUsefulSeconds;
        }

        return $clone;
    }

    public function withCooldown(int $threshold, int $failureWindowSeconds, int $cooldownSeconds): self
    {
        self::atLeast($threshold, 1, 'cooldown threshold');
        self::atLeast($failureWindowSeconds, 1, 'failure window');
        self::atLeast($cooldownSeconds, 1, 'cooldown time');
        $clone = clone $this;
        $clone->cooldownThreshold = $threshold;
        $clone->failureWindow = $failureWindowSeconds;
        $clone->cooldownSeconds = $cooldownSeconds;

        return $clone;
    }

    /** 0 turns the credit hold off. */
    public function withCreditHold(int $seconds): self
    {
        self::atLeast($seconds, 0, 'credit hold');

        return $this->set('creditHoldSeconds', $seconds);
    }

    public function withLogThrottle(int $seconds): self
    {
        self::atLeast($seconds, 1, 'log throttle');

        return $this->set('logThrottleSeconds', $seconds);
    }

    /** How long a learned strict-to-JSON downgrade is remembered in the store. */
    public function withDowngradeMemory(int $seconds): self
    {
        self::atLeast($seconds, 1, 'downgrade memory');

        return $this->set('downgradeSeconds', $seconds);
    }

    public function withMaxResponseBytes(int $bytes): self
    {
        self::atLeast($bytes, 1024, 'response size cap');

        return $this->set('maxResponseBytes', $bytes);
    }

    public function withStoreKeyPrefix(string $prefix): self
    {
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix) !== 1) {
            throw new ConfigurationException('Store key prefix must be 1 to 32 letters, digits or underscores.');
        }

        return $this->set('storeKeyPrefix', $prefix);
    }

    /** Off: one attempt per route, no cooldowns, no holds, no store traffic. */
    public function withReliability(bool $on): self
    {
        return $this->set('reliability', $on);
    }

    /** On: a success after a credit failure still throws CreditExhaustedException. */
    public function withStrictCredit(bool $on): self
    {
        return $this->set('strictCredit', $on);
    }

    /** On: failure log lines carry the first 200 characters of the output. */
    public function withExcerptLogging(bool $on): self
    {
        return $this->set('excerptLogging', $on);
    }

    public function attemptsPerRoute(): int
    {
        return $this->attemptsPerRoute;
    }

    public function defaultTokenCap(): int
    {
        return $this->defaultTokenCap;
    }

    public function retryBaseDelay(): float
    {
        return $this->retryBaseDelay;
    }

    public function retryMaxDelay(): float
    {
        return $this->retryMaxDelay;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function requestTimeout(): float
    {
        return $this->requestTimeout;
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function minUsefulTime(): float
    {
        return $this->minUsefulTime;
    }

    public function cooldownThreshold(): int
    {
        return $this->cooldownThreshold;
    }

    public function failureWindow(): int
    {
        return $this->failureWindow;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    public function creditHoldSeconds(): int
    {
        return $this->creditHoldSeconds;
    }

    public function logThrottleSeconds(): int
    {
        return $this->logThrottleSeconds;
    }

    public function downgradeSeconds(): int
    {
        return $this->downgradeSeconds;
    }

    public function maxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    public function storeKeyPrefix(): string
    {
        return $this->storeKeyPrefix;
    }

    public function reliability(): bool
    {
        return $this->reliability;
    }

    public function strictCredit(): bool
    {
        return $this->strictCredit;
    }

    public function excerptLogging(): bool
    {
        return $this->excerptLogging;
    }

    private function set(string $property, int|float|string|bool $value): self
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }

    private static function atLeast(int $value, int $min, string $what): void
    {
        if ($value < $min) {
            throw new ConfigurationException(sprintf('The %s must be at least %d, got %d.', $what, $min, $value));
        }
    }
}
