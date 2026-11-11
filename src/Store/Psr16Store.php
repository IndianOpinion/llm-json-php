<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Store;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\StoreException;

/**
 * Wraps any PSR-16 cache. Needs psr/simple-cache, which the library does
 * not require. PSR-16 has no atomic increment or add, so two processes
 * failing at the same moment can lose an increment (a cooldown then starts
 * one failure late) and a log line can be written twice.
 */
final class Psr16Store implements Store
{
    private const PSR_INTERFACE = 'Psr\\SimpleCache\\CacheInterface';

    private object $cache;

    public function __construct(object $cache)
    {
        if (!interface_exists(self::PSR_INTERFACE)) {
            throw new ConfigurationException('Psr16Store needs psr/simple-cache.');
        }
        if (!is_a($cache, self::PSR_INTERFACE)) {
            throw new ConfigurationException(sprintf('Psr16Store needs a %s, got %s.', self::PSR_INTERFACE, $cache::class));
        }
        $this->cache = $cache;
    }

    public function get(string $key): ?int
    {
        $value = $this->call('get', $key, null);
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new StoreException('PSR-16 cache returned a value that is not an integer.');
        }

        return $value;
    }

    public function set(string $key, int $value, int $ttl): void
    {
        if ($this->call('set', $key, $value, max(1, $ttl)) !== true) {
            throw new StoreException('PSR-16 cache refused a write.');
        }
    }

    public function delete(string $key): void
    {
        $this->call('delete', $key);
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

    private function call(string $method, mixed ...$args): mixed
    {
        $callable = [$this->cache, $method];
        if (!is_callable($callable)) {
            throw new StoreException(sprintf('PSR-16 cache has no %s method.', $method));
        }
        try {
            return $callable(...$args);
        } catch (\Throwable $e) {
            throw new StoreException('PSR-16 cache failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
