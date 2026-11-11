<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Store;

use IndianOpinion\LlmJson\Clock\Clock;
use IndianOpinion\LlmJson\Clock\SystemClock;

/**
 * Lives for the process. Right for long-running workers and tests; it
 * remembers nothing across web requests.
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{0: int, 1: float}> */
    private array $entries = [];
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?int
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry[1] <= $this->clock->now()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry[0];
    }

    public function set(string $key, int $value, int $ttl): void
    {
        $this->entries[$key] = [$value, $this->clock->now() + max(1, $ttl)];
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function increment(string $key, int $ttl): int
    {
        $value = ($this->get($key) ?? 0) + 1;
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        $this->set($key, $value, $ttl);

        return true;
    }

    /** Number of live entries. For tests and status pages. */
    public function count(): int
    {
        $now = $this->clock->now();

        return count(array_filter($this->entries, static fn (array $e): bool => $e[1] > $now));
    }
}
