<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * A provider plus a model id, with optional per-route settings. Immutable.
 */
final class Route
{
    /** Body fields that extra body fields may never overwrite. */
    private const PROTECTED_FIELDS = ['model', 'messages', 'response_format', 'max_tokens', 'max_completion_tokens', 'stream', 'n'];

    private OutputMode $mode = OutputMode::Auto;
    private ?int $attempts = null;
    private ?int $tokenCap = null;
    private ?float $temperature = null;
    private ?bool $reasoning = null;
    private string $offEffort = 'low';
    /** @var list<string>|null */
    private ?array $hostOrder = null;
    private ?bool $allowFallbacks = null;
    private bool $requireParameters = true;
    private bool $usageReporting = true;
    /** @var array<string, mixed> */
    private array $extraBody = [];
    private ?float $connectTimeout = null;
    private ?float $requestTimeout = null;

    private function __construct(private readonly Provider $provider, private readonly string $model)
    {
        if (trim($model) === '' || strlen($model) > 200 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new ConfigurationException('Model id must be a non-empty string of at most 200 printable characters.');
        }
    }

    public static function to(Provider $provider, string $model): self
    {
        return new self($provider, $model);
    }

    public function withMode(OutputMode $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;

        return $clone;
    }

    /** Attempts on this route, including the first. Overrides Options. */
    public function withAttempts(int $attempts): self
    {
        if ($attempts < 1) {
            throw new ConfigurationException('A route needs at least 1 attempt.');
        }
        $clone = clone $this;
        $clone->attempts = $attempts;

        return $clone;
    }

    public function withTokenCap(int $cap): self
    {
        $clone = clone $this;
        $clone->tokenCap = self::checkTokenCap($cap);

        return $clone;
    }

    public function withTemperature(float $temperature): self
    {
        $clone = clone $this;
        $clone->temperature = self::checkTemperature($temperature);

        return $clone;
    }

    /**
     * Turn hidden reasoning off. OpenRouter gets reasoning.enabled false;
     * OpenAI and generic providers get reasoning_effort set to the given
     * effort, the lowest the model accepts (for example "minimal" or "none"
     * on models that support it).
     */
    public function withoutReasoning(string $effort = 'low'): self
    {
        if (preg_match('/^[a-z]{1,16}$/', $effort) !== 1) {
            throw new ConfigurationException('Reasoning effort must be a short lower-case word.');
        }
        $clone = clone $this;
        $clone->reasoning = false;
        $clone->offEffort = $effort;

        return $clone;
    }

    /**
     * OpenRouter upstream host preferences, passed through unchanged.
     *
     * @param list<string> $order
     */
    public function withHostPreferences(array $order, ?bool $allowFallbacks = null): self
    {
        foreach ($order as $host) {
            if (!is_string($host) || $host === '') {
                throw new ConfigurationException('Host preferences must be non-empty strings.');
            }
        }
        $clone = clone $this;
        $clone->hostOrder = array_values($order);
        $clone->allowFallbacks = $allowFallbacks;

        return $clone;
    }

    /** OpenRouter: only use upstream hosts that honour every parameter. On by default. */
    public function withRequireParameters(bool $on): self
    {
        $clone = clone $this;
        $clone->requireParameters = $on;

        return $clone;
    }

    /** OpenRouter: ask for usage accounting in the response. On by default. */
    public function withUsageReporting(bool $on): self
    {
        $clone = clone $this;
        $clone->usageReporting = $on;

        return $clone;
    }

    /**
     * Extra body fields such as seed or top_p, merged last. They may not
     * set model, messages, response_format, the token cap, stream (not
     * supported) or n (one answer per attempt).
     *
     * @param array<string, mixed> $fields
     */
    public function withExtraBody(array $fields): self
    {
        foreach (array_keys($fields) as $field) {
            if (in_array($field, self::PROTECTED_FIELDS, true) || $field === $this->provider->tokenParameter()) {
                throw new ConfigurationException(sprintf('Extra body field "%s" would overwrite a field the library owns.', $field));
            }
        }
        $clone = clone $this;
        $clone->extraBody = array_merge($this->extraBody, $fields);

        return $clone;
    }

    public function withTimeouts(?float $connectSeconds, ?float $requestSeconds): self
    {
        if (($connectSeconds !== null && (!($connectSeconds > 0) || !is_finite($connectSeconds))) || ($requestSeconds !== null && (!($requestSeconds > 0) || !is_finite($requestSeconds)))) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }
        $clone = clone $this;
        $clone->connectTimeout = $connectSeconds;
        $clone->requestTimeout = $requestSeconds;

        return $clone;
    }

    public function provider(): Provider
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function mode(): OutputMode
    {
        return $this->mode;
    }

    public function attempts(): ?int
    {
        return $this->attempts;
    }

    public function tokenCap(): ?int
    {
        return $this->tokenCap;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function reasoningDisabled(): bool
    {
        return $this->reasoning === false;
    }

    public function reasoningEffortWhenOff(): string
    {
        return $this->offEffort;
    }

    /** @return list<string>|null */
    public function hostOrder(): ?array
    {
        return $this->hostOrder;
    }

    public function allowFallbacks(): ?bool
    {
        return $this->allowFallbacks;
    }

    public function requireParameters(): bool
    {
        return $this->requireParameters;
    }

    public function usageReporting(): bool
    {
        return $this->usageReporting;
    }

    /** @return array<string, mixed> */
    public function extraBody(): array
    {
        return $this->extraBody;
    }

    public function connectTimeout(): ?float
    {
        return $this->connectTimeout;
    }

    public function requestTimeout(): ?float
    {
        return $this->requestTimeout;
    }

    /** Identity of the route for cooldowns: provider identity plus requested model. */
    public function id(): string
    {
        return substr(hash('sha256', $this->provider->id() . "\0" . $this->model), 0, 16);
    }

    public function label(): string
    {
        return $this->provider->name() . '/' . $this->model;
    }

    /** @internal */
    public static function checkTemperature(float $temperature): float
    {
        if (!is_finite($temperature) || $temperature < 0 || $temperature > 2) {
            throw new ConfigurationException(sprintf('Temperature must be between 0 and 2, got %s.', (string) $temperature));
        }

        return $temperature;
    }

    /** @internal */
    public static function checkTokenCap(int $cap): int
    {
        if ($cap < 1) {
            throw new ConfigurationException(sprintf('Token cap must be at least 1, got %d.', $cap));
        }

        return $cap;
    }
}
