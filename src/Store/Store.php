<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Store;

/**
 * Small key-value store for cooldowns, holds and counters. Values are
 * integers. Every entry has a TTL in seconds. Keys are short ASCII strings.
 *
 * Implementations throw StoreException on failure; the client fails open.
 */
interface Store
{
    /** The value, or null when absent or expired. */
    public function get(string $key): ?int;

    public function set(string $key, int $value, int $ttl): void;

    public function delete(string $key): void;

    /** Add one and refresh the TTL. Returns the new value (1 when absent). */
    public function increment(string $key, int $ttl): int;

    /** Set only when absent. True when this call set it. */
    public function add(string $key, int $value, int $ttl): bool;
}
