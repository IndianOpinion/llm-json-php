<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson;

/**
 * Token counts and cost. A null count means the provider did not say;
 * it is never reported as zero.
 */
final class Usage
{
    public function __construct(
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?int $reasoningTokens = null,
        public readonly ?int $cachedTokens = null,
        public readonly ?float $cost = null,
        public readonly CostSource $costSource = CostSource::Unknown,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function totalTokens(): ?int
    {
        if ($this->promptTokens === null && $this->completionTokens === null) {
            return null;
        }

        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }

    public function withCost(?float $cost, CostSource $source): self
    {
        return new self(
            $this->promptTokens,
            $this->completionTokens,
            $this->reasoningTokens,
            $this->cachedTokens,
            $cost,
            $source,
        );
    }

    /**
     * Sum usages. Each count is the sum of the known values, or null when
     * none is known.
     *
     * @param iterable<Usage> $usages
     */
    public static function sum(iterable $usages): self
    {
        $fields = ['promptTokens' => null, 'completionTokens' => null, 'reasoningTokens' => null, 'cachedTokens' => null];
        $cost = null;
        $sources = [];
        foreach ($usages as $usage) {
            foreach (array_keys($fields) as $name) {
                $value = $usage->{$name};
                if ($value !== null) {
                    $fields[$name] = ($fields[$name] ?? 0) + $value;
                }
            }
            if ($usage->cost !== null) {
                $cost = ($cost ?? 0.0) + $usage->cost;
                $sources[$usage->costSource->value] = $usage->costSource;
            }
        }
        $source = match (count($sources)) {
            0 => CostSource::Unknown,
            1 => array_values($sources)[0],
            default => CostSource::Mixed,
        };

        return new self(
            $fields['promptTokens'],
            $fields['completionTokens'],
            $fields['reasoningTokens'],
            $fields['cachedTokens'],
            $cost,
            $source,
        );
    }
}
