<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Store;

use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\StoreException;

/**
 * Keeps state in WordPress transients: site transients on multisite, plain
 * transients otherwise. You may pass the get, set and delete functions in
 * yourself (useful in tests); by default the WordPress functions are used.
 *
 * With a persistent object cache that supports it, add-if-absent uses
 * wp_cache_add() and is atomic. Otherwise it is get then set, and a
 * duplicate log line is possible. Object caches may evict early; a lost
 * cooldown means one extra attempt.
 */
final class WordPressTransientStore implements Store
{
    /** Site transient names are limited to 167 characters. */
    public const MAX_KEY_LENGTH = 167;

    /** @var callable(string): mixed */
    private $getFn;
    /** @var callable(string, mixed, int): mixed */
    private $setFn;
    /** @var callable(string): mixed */
    private $deleteFn;
    private bool $network;
    private bool $nativeAdd;

    /**
     * @param (callable(string): mixed)|null            $get
     * @param (callable(string, mixed, int): mixed)|null $set
     * @param (callable(string): mixed)|null            $delete
     * @param bool|null $network true for site transients; null asks is_multisite()
     */
    public function __construct(?callable $get = null, ?callable $set = null, ?callable $delete = null, ?bool $network = null)
    {
        $injected = $get !== null || $set !== null || $delete !== null;
        if ($injected && ($get === null || $set === null || $delete === null)) {
            throw new ConfigurationException('Pass all three transient functions, or none.');
        }
        $this->network = $network ?? (function_exists('is_multisite') && is_multisite());
        if (!$injected) {
            $names = $this->network
                ? ['get_site_transient', 'set_site_transient', 'delete_site_transient']
                : ['get_transient', 'set_transient', 'delete_transient'];
            foreach ($names as $name) {
                if (!function_exists($name)) {
                    throw new ConfigurationException('WordPressTransientStore needs WordPress loaded, or the transient functions passed in.');
                }
            }
            [$get, $set, $delete] = $names;
        }
        /** @var callable(string): mixed $get */
        $this->getFn = $get;
        /** @var callable(string, mixed, int): mixed $set */
        $this->setFn = $set;
        /** @var callable(string): mixed $delete */
        $this->deleteFn = $delete;
        $this->nativeAdd = !$injected
            && function_exists('wp_using_ext_object_cache')
            && function_exists('wp_cache_add')
            && wp_using_ext_object_cache();
    }

    public function isNetwork(): bool
    {
        return $this->network;
    }

    public function get(string $key): ?int
    {
        $value = $this->invoke($this->getFn, $this->check($key));
        if ($value === false || $value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (!is_int($value)) {
            throw new StoreException('Transient holds a value that is not an integer.');
        }

        return $value;
    }

    public function set(string $key, int $value, int $ttl): void
    {
        if ($this->invoke($this->setFn, $this->check($key), $value, max(1, $ttl)) === false && $this->get($key) !== $value) {
            // set_transient() also returns false when the stored value is
            // unchanged (update_option() reports "no change" as false); only
            // a value that did not land is a failure.
            throw new StoreException('WordPress refused to save a transient.');
        }
    }

    public function delete(string $key): void
    {
        $this->invoke($this->deleteFn, $this->check($key));
    }

    public function increment(string $key, int $ttl): int
    {
        $value = ($this->get($key) ?? 0) + 1;
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        if ($this->nativeAdd) {
            $key = $this->check($key);
            try {
                return (bool) wp_cache_add($key, $value, $this->network ? 'site-transient' : 'transient', max(1, $ttl));
            } catch (\Throwable $e) {
                throw new StoreException('WordPress cache add failed: ' . $e->getMessage(), 0, $e);
            }
        }
        if ($this->get($key) !== null) {
            return false;
        }
        $this->set($key, $value, $ttl);

        return true;
    }

    private function check(string $key): string
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new StoreException('Transient key is too long.');
        }

        return $key;
    }

    private function invoke(callable $fn, mixed ...$args): mixed
    {
        try {
            return $fn(...$args);
        } catch (\Throwable $e) {
            throw new StoreException('WordPress transient call failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
