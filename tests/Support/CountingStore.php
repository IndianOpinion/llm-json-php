<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Support;

use IndianOpinion\LlmJson\Store\Store;

/**
 * Wraps a store and counts operations; can be told to throw.
 */
final class CountingStore implements Store
{
    public int $operations = 0;

    public function __construct(private readonly Store $inner, private readonly bool $broken = false)
    {
    }

    private function tick(): void
    {
        $this->operations++;
        if ($this->broken) {
            throw new \RuntimeException('cache server went away');
        }
    }

    public function get(string $key): ?int
    {
        $this->tick();

        return $this->inner->get($key);
    }

    public function set(string $key, int $value, int $ttl): void
    {
        $this->tick();
        $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->tick();
        $this->inner->delete($key);
    }

    public function increment(string $key, int $ttl): int
    {
        $this->tick();

        return $this->inner->increment($key, $ttl);
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        $this->tick();

        return $this->inner->add($key, $value, $ttl);
    }
}
