<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

use IndianOpinion\LlmJson\Exception\ConfigurationException;

/**
 * Prices per million tokens, per model id. The library never guesses a
 * price: a model that is not in the table has an unknown cost.
 */
final class PriceTable
{
    /** @var array<string, array{input: float, output: float, cached_input: ?float}> */
    private array $prices = [];

    /**
     * @param array<string, array{input: float|int, output: float|int, cached_input?: float|int|null}> $prices
     */
    public static function fromArray(array $prices): self
    {
        $table = new self();
        foreach ($prices as $model => $price) {
            $table = $table->with((string) $model, (float) $price['input'], (float) $price['output'], isset($price['cached_input']) ? (float) $price['cached_input'] : null);
        }

        return $table;
    }

    public function with(string $model, float $inputPerMillion, float $outputPerMillion, ?float $cachedInputPerMillion = null): self
    {
        if ($model === '') {
            throw new ConfigurationException('Price table model id must not be empty.');
        }
        foreach ([$inputPerMillion, $outputPerMillion, $cachedInputPerMillion ?? 0.0] as $price) {
            if ($price < 0 || !is_finite($price)) {
                throw new ConfigurationException(sprintf('Price for model "%s" must be a finite number of zero or more.', $model));
            }
        }
        $clone = clone $this;
        $clone->prices[$model] = ['input' => $inputPerMillion, 'output' => $outputPerMillion, 'cached_input' => $cachedInputPerMillion];

        return $clone;
    }

    public function has(string $model): bool
    {
        return isset($this->prices[$model]);
    }

    /**
     * Cost for the usage, or null when the model is unknown or the token
     * counts are missing. Cached prompt tokens use the cached price when one
     * is set, else the input price.
     */
    public function cost(string $model, Usage $usage): ?float
    {
        $price = $this->prices[$model] ?? null;
        if ($price === null || $usage->promptTokens === null || $usage->completionTokens === null) {
            return null;
        }
        $cached = min($usage->cachedTokens ?? 0, $usage->promptTokens);
        $fresh = $usage->promptTokens - $cached;
        $cachedPrice = $price['cached_input'] ?? $price['input'];

        return ($fresh * $price['input'] + $cached * $cachedPrice + $usage->completionTokens * $price['output']) / 1_000_000;
    }
}
