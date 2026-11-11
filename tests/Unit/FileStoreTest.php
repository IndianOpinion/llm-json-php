<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Tests\Unit;

use IndianOpinion\LlmJson\Exception\StoreException;
use IndianOpinion\LlmJson\Store\FileStore;
use IndianOpinion\LlmJson\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

/** T105 and T106. */
final class FileStoreTest extends TestCase
{
    private string $dir;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmjson-file-' . bin2hex(random_bytes(6)) . '/nested';
        $this->clock = new FrozenClock();
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, dirname($this->dir)] as $dir) {
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $file) {
                    if (is_file($dir . '/' . $file)) {
                        unlink($dir . '/' . $file);
                    }
                }
                rmdir($dir);
            }
        }
    }

    public function testT106ModesAndAtomicReplace(): void
    {
        $store = new FileStore($this->dir, $this->clock, 0);
        $store->set('llmjson.c.abc', 1, 60);
        $store->set('llmjson.c.abc', 2, 60);
        self::assertSame(0700, fileperms($this->dir) & 0777);
        $files = glob($this->dir . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertMatchesRegularExpression('#/[0-9a-f]{64}\.json$#', $files[0]);
        self::assertSame([], glob($this->dir . '/.tmp-*') ?: []);
        self::assertSame(2, $store->get('llmjson.c.abc'));
        self::assertStringNotContainsString('llmjson', (string) file_get_contents($files[0]), 'the key name is not stored');
    }

    public function testT106ExpiredGoneOnReadAndSweepBounded(): void
    {
        $store = new FileStore($this->dir, $this->clock, 0);
        $store->set('short', 1, 10);
        $this->clock->advance(11);
        self::assertNull($store->get('short'));
        self::assertSame([], glob($this->dir . '/*.json') ?: []);

        for ($i = 0; $i < 120; $i++) {
            $store->set('k' . $i, $i, 5);
        }
        $store->set('keep', 1, 1000);
        $this->clock->advance(6);
        $total = 0;
        $sweeps = 0;
        while (($removed = $store->sweep()) > 0) {
            self::assertLessThanOrEqual(FileStore::SWEEP_LIMIT, $removed);
            $total += $removed;
            $sweeps++;
        }
        self::assertSame(120, $total);
        self::assertGreaterThanOrEqual(3, $sweeps);
        self::assertSame(1, $store->get('keep'));
    }

    public function testCorruptEntryIsAStoreError(): void
    {
        $store = new FileStore($this->dir, $this->clock, 0);
        $store->set('x', 1, 60);
        $files = glob($this->dir . '/*.json') ?: [];
        file_put_contents($files[0], 'not json');
        $this->expectException(StoreException::class);
        $store->get('x');
    }

    public function testUnwritableDirectoryIsAStoreError(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $file = sys_get_temp_dir() . '/llmjson-blocker-' . bin2hex(random_bytes(4));
            touch($file);
            $store = new FileStore($file . '/sub', $this->clock, 0);
            try {
                $this->expectException(StoreException::class);
                $store->set('x', 1, 1);
            } finally {
                unlink($file);
            }

            return;
        }
        $store = new FileStore('/proc/llmjson-not-allowed', $this->clock, 0);
        $this->expectException(StoreException::class);
        $store->set('x', 1, 1);
    }

    public function testT105ConcurrentIncrementsAndAdd(): void
    {
        $store = new FileStore($this->dir, $this->clock, 0);
        $store->set('warmup', 1, 60);
        $workers = 4;
        $each = 25;
        if (!function_exists('pcntl_fork')) {
            // Interleaved operations from two clients on one directory.
            $a = new FileStore($this->dir, $this->clock, 0);
            $b = new FileStore($this->dir, $this->clock, 0);
            for ($i = 0; $i < $each; $i++) {
                $a->increment('counter', 600);
                $b->increment('counter', 600);
            }
            self::assertSame(2 * $each, $a->get('counter'));
            self::assertTrue($a->add('once', 1, 60));
            self::assertFalse($b->add('once', 1, 60));

            return;
        }
        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $child = new FileStore($this->dir, new FrozenClock($this->clock->now()), 0);
                for ($i = 0; $i < $each; $i++) {
                    $child->increment('counter', 600);
                }
                $won = $child->add('once', 1, 60);
                exit($won ? 10 : 20);
            }
            $pids[] = $pid;
        }
        $winners = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $winners += pcntl_wexitstatus($status) === 10 ? 1 : 0;
        }
        self::assertSame($workers * $each, $store->get('counter'));
        self::assertSame(1, $winners);
    }
}
