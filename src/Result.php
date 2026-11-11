<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Guard\GuardReport;

/**
 * The validated data and the full story of the call.
 */
final class Result
{
    /**
     * @param array<mixed>           $data
     * @param list<Attempt>          $attempts
     * @param list<Skip>             $skips
     * @param list<CreditExhaustion> $exhaustedProviders
     * @param list<string>           $storeErrors
     * @param list<string>           $listenerErrors
     * @param array<mixed>|null      $originalData
     */
    public function __construct(
        private readonly array $data,
        private readonly string $purpose,
        private readonly string $provider,
        private readonly string $model,
        private readonly ?string $reportedModel,
        private readonly OutputMode $mode,
        private readonly string $modeReason,
        private readonly array $attempts,
        private readonly array $skips,
        private readonly Usage $usage,
        private readonly Usage $totalUsage,
        private readonly GuardReport $guard,
        private readonly array $exhaustedProviders,
        private readonly array $storeErrors,
        private readonly array $listenerErrors,
        private readonly int $durationMs,
        private readonly ?string $raw = null,
        private readonly ?array $originalData = null,
        private readonly bool $fenceUnwrapped = false,
    ) {
    }

    /** @return array<mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    /** Name of the provider that answered. */
    public function provider(): string
    {
        return $this->provider;
    }

    /** The model the successful route asked for. */
    public function model(): string
    {
        return $this->model;
    }

    /** The model the provider says answered, if it said. */
    public function reportedModel(): ?string
    {
        return $this->reportedModel;
    }

    public function mode(): OutputMode
    {
        return $this->mode;
    }

    public function modeReason(): string
    {
        return $this->modeReason;
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

    /** Usage of the successful attempt. */
    public function usage(): Usage
    {
        return $this->usage;
    }

    /** Usage of every billed attempt in the call, failures included. */
    public function totalUsage(): Usage
    {
        return $this->totalUsage;
    }

    public function guardReport(): GuardReport
    {
        return $this->guard;
    }

    /** @return list<CreditExhaustion> */
    public function exhaustedProviders(): array
    {
        return $this->exhaustedProviders;
    }

    public function hadCreditExhaustion(): bool
    {
        return $this->exhaustedProviders !== [];
    }

    /** @return list<string> */
    public function storeErrors(): array
    {
        return $this->storeErrors;
    }

    /** @return list<string> */
    public function listenerErrors(): array
    {
        return $this->listenerErrors;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    /** Raw content of the successful attempt, only with keep-raw on. */
    public function raw(): ?string
    {
        return $this->raw;
    }

    /**
     * Data before guard repairs, only with keep-raw on.
     *
     * @return array<mixed>|null
     */
    public function originalData(): ?array
    {
        return $this->originalData;
    }

    public function fenceUnwrapped(): bool
    {
        return $this->fenceUnwrapped;
    }

    /** True when the answer came from a route after the first. */
    public function usedFallback(): bool
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->succeeded()) {
                return $attempt->routeIndex > 0;
            }
        }

        return false;
    }
}
