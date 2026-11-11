<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Support;

use IndianOpinion\LlmJson\Testing\FrozenClock;
use Psr\SimpleCache\CacheInterface;

/**
 * A minimal PSR-16 cache for the store contract tests.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{0: mixed, 1: float|null}> */
    private array $items = [];

    public function __construct(private readonly FrozenClock $clock)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->items[$key] ?? null;
        if ($item === null || ($item[1] !== null && $item[1] <= $this->clock->now())) {
            unset($this->items[$key]);

            return $default;
        }

        return $item[0];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new \InvalidArgumentException('reserved character in key');
        }
        $this->items[$key] = [$value, is_int($ttl) ? $this->clock->now() + $ttl : null];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /** @return iterable<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
