<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\StoreException;
use IndianOpinion\LlmJson\Store\FileStore;
use IndianOpinion\LlmJson\Store\MemoryStore;
use IndianOpinion\LlmJson\Store\Psr16Store;
use IndianOpinion\LlmJson\Store\Store;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Testing\FrozenClock;
use IndianOpinion\LlmJson\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** T107, T108, T109 and the shared half of T106 and T111: every store keeps the same contract. */
final class StoreContractTest extends TestCase
{
    private static ?string $dir = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$dir === null) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$dir);
    }

    /** @return iterable<string, array{callable(FrozenClock): Store, bool}> */
    public static function stores(): iterable
    {
        yield 'memory' => [static fn (FrozenClock $c): Store => new MemoryStore($c), true];
        yield 'file' => [static function (FrozenClock $c): Store {
            self::$dir ??= sys_get_temp_dir() . '/llmjson-contract-' . bin2hex(random_bytes(4));

            return new FileStore(self::$dir . '/' . bin2hex(random_bytes(4)), $c, 0);
        }, true];
        yield 'psr16' => [static fn (FrozenClock $c): Store => new Psr16Store(new ArrayCache($c)), true];
        yield 'wordpress injected' => [static function (FrozenClock $c): Store {
            $data = new \ArrayObject();

            return new WordPressTransientStore(
                static function (string $k) use ($data, $c): mixed {
                    $e = $data[$k] ?? null;

                    return $e === null || $e[1] <= $c->now() ? false : $e[0];
                },
                static function (string $k, mixed $v, int $ttl) use ($data, $c): bool {
                    $data[$k] = [$v, $c->now() + $ttl];

                    return true;
                },
                static function (string $k) use ($data): bool {
                    unset($data[$k]);

                    return true;
                },
            );
        }, false];
    }

    /** @param callable(FrozenClock): Store $make */
    #[DataProvider('stores')]
    public function testContract(callable $make, bool $honoursClock): void
    {
        $clock = new FrozenClock();
        $store = $make($clock);
        $key = 'llmjson.c.0123456789abcdef';
        self::assertNull($store->get($key));
        $store->set($key, 42, 60);
        self::assertSame(42, $store->get($key));
        $store->delete($key);
        self::assertNull($store->get($key));
        $store->delete($key);

        self::assertSame(1, $store->increment('llmjson.f.x', 100));
        self::assertSame(2, $store->increment('llmjson.f.x', 100));
        self::assertSame(3, $store->increment('llmjson.f.x', 100));

        self::assertTrue($store->add('llmjson.t.x', 1, 50));
        self::assertFalse($store->add('llmjson.t.x', 2, 50));
        self::assertSame(1, $store->get('llmjson.t.x'));

        if ($honoursClock) {
            $clock->advance(61);
            self::assertSame(3, $store->get('llmjson.f.x'), 'increment refreshes the TTL from the last write');
            $clock->advance(100);
            self::assertNull($store->get('llmjson.f.x'));
            self::assertNull($store->get('llmjson.t.x'));
            self::assertTrue($store->add('llmjson.t.x', 5, 50), 'add succeeds after expiry');
        }
    }

    public function testT109WordPressStoreWithFunctions(): void
    {
        require_once __DIR__ . '/../stubs/wordpress.php';
        llmjson_wp_reset();
        $store = new WordPressTransientStore();
        self::assertFalse($store->isNetwork());
        $store->set('llmjson.c.abc', 7, 600);
        self::assertSame(['value' => 7, 'ttl' => 600], $GLOBALS['llmjson_wp']['transients']['llmjson.c.abc']);
        self::assertSame(7, $store->get('llmjson.c.abc'));
        self::assertTrue($store->add('llmjson.t.abc', 1, 600));
        self::assertFalse($store->add('llmjson.t.abc', 1, 600));

        $GLOBALS['llmjson_wp']['multisite'] = true;
        $network = new WordPressTransientStore();
        self::assertTrue($network->isNetwork());
        $network->set('llmjson.h.abc', 9, 60);
        self::assertArrayHasKey('llmjson.h.abc', $GLOBALS['llmjson_wp']['site_transients']);
        self::assertSame(9, $network->get('llmjson.h.abc'));

        $GLOBALS['llmjson_wp']['ext_cache'] = true;
        $cached = new WordPressTransientStore();
        self::assertTrue($cached->add('llmjson.t.zzz', 1, 60));
        self::assertFalse($cached->add('llmjson.t.zzz', 1, 60));
        self::assertArrayHasKey('llmjson.t.zzz', $GLOBALS['llmjson_wp']['cache']['site-transient']);

        $GLOBALS['llmjson_wp']['transients']['llmjson.c.bad'] = ['value' => ['not' => 'int'], 'ttl' => 1];
        $this->expectException(StoreException::class);
        $store->get('llmjson.c.bad');
    }

    public function testT109KeysWithinTransientLimit(): void
    {
        $prefix = str_repeat('p', 32);
        $key = $prefix . '.n.20261111';
        self::assertLessThanOrEqual(WordPressTransientStore::MAX_KEY_LENGTH, strlen($prefix . '.c.' . str_repeat('a', 16)));
        self::assertLessThanOrEqual(64, strlen($key));
        $store = new WordPressTransientStore(static fn () => false, static fn () => true, static fn () => true);
        $this->expectException(StoreException::class);
        $store->get(str_repeat('k', 200));
    }

    public function testT108Psr16RejectsGarbageAndWrongObject(): void
    {
        $cache = new ArrayCache(new FrozenClock());
        $cache->set('llmjson.c.x', 'garbage');
        $store = new Psr16Store($cache);
        try {
            $store->get('llmjson.c.x');
            self::fail('expected exception');
        } catch (StoreException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\IndianOpinion\LlmJson\Exception\ConfigurationException::class);
        new Psr16Store(new \stdClass());
    }
}
