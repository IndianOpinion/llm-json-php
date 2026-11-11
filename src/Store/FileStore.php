<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Store;

use IndianOpinion\LlmJson\Clock\Clock;
use IndianOpinion\LlmJson\Clock\SystemClock;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\StoreException;
use IndianOpinion\LlmJson\Internal\ErrorTrap;

/**
 * One small JSON file per key in a directory the caller names. Shared by
 * every process on the host.
 *
 * The directory is created with mode 0700 and files are written 0600.
 * Writes go to a temporary file that is renamed into place. Increments and
 * add-if-absent hold an exclusive lock on a per-key lock file. File names
 * are hex hashes of the key, so no path traversal is possible. Expired
 * files are removed on read and by an occasional bounded sweep.
 */
final class FileStore implements Store
{
    public const SWEEP_LIMIT = 50;

    private string $directory;
    private Clock $clock;
    private bool $ready = false;

    /**
     * @param int $sweepOneIn run a sweep on about one call in this many (0 turns sweeping off)
     */
    public function __construct(string $directory, ?Clock $clock = null, private readonly int $sweepOneIn = 100)
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new ConfigurationException('FileStore needs a directory path.');
        }
        $this->directory = $directory;
        $this->clock = $clock ?? new SystemClock();
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function get(string $key): ?int
    {
        $this->maybeSweep();
        $path = $this->path($key);
        $entry = $this->read($path);
        if ($entry === null) {
            return null;
        }
        if ($entry['e'] <= $this->clock->now()) {
            $this->removeIfExpired($path);

            return null;
        }

        return $entry['v'];
    }

    public function set(string $key, int $value, int $ttl): void
    {
        $this->maybeSweep();
        $this->write($this->path($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->unlink($this->path($key));
    }

    public function increment(string $key, int $ttl): int
    {
        return $this->locked($key, function (string $path) use ($ttl): int {
            $entry = $this->read($path);
            $value = ($entry !== null && $entry['e'] > $this->clock->now()) ? $entry['v'] + 1 : 1;
            $this->write($path, $value, $ttl);

            return $value;
        });
    }

    public function add(string $key, int $value, int $ttl): bool
    {
        return $this->locked($key, function (string $path) use ($value, $ttl): bool {
            $entry = $this->read($path);
            if ($entry !== null && $entry['e'] > $this->clock->now()) {
                return false;
            }
            $this->write($path, $value, $ttl);

            return true;
        });
    }

    /**
     * Remove expired entries, looking at no more than $limit files.
     * Returns the number removed.
     */
    public function sweep(int $limit = self::SWEEP_LIMIT): int
    {
        $this->ensureDirectory();
        [$handle] = ErrorTrap::run(fn () => opendir($this->directory));
        if (!is_resource($handle)) {
            return 0;
        }
        $looked = 0;
        $removed = 0;
        while ($looked < $limit && ($name = readdir($handle)) !== false) {
            if (preg_match('/^[0-9a-f]{64}\.json$/', $name) !== 1) {
                continue;
            }
            $looked++;
            if ($this->removeIfExpired($this->directory . '/' . $name)) {
                $removed++;
            }
        }
        closedir($handle);

        return $removed;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function ensureDirectory(): void
    {
        if ($this->ready) {
            return;
        }
        if (!is_dir($this->directory)) {
            [$ok, $warning] = ErrorTrap::run(fn (): bool => mkdir($this->directory, 0700, true));
            if (!$ok && !is_dir($this->directory)) {
                throw new StoreException('FileStore could not create its directory: ' . ($warning ?? 'unknown error'));
            }
            ErrorTrap::run(fn (): bool => chmod($this->directory, 0700));
        }
        if (!is_writable($this->directory)) {
            throw new StoreException('FileStore directory is not writable.');
        }
        $this->ready = true;
    }

    /**
     * @return array{v: int, e: float}|null
     */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        [$raw] = ErrorTrap::run(static fn () => file_get_contents($path));
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_int($data['v'] ?? null) || !is_numeric($data['e'] ?? null)) {
            throw new StoreException('FileStore found a corrupt entry.');
        }

        return ['v' => $data['v'], 'e' => (float) $data['e']];
    }

    private function write(string $path, int $value, int $ttl): void
    {
        $this->ensureDirectory();
        $payload = json_encode(['v' => $value, 'e' => $this->clock->now() + max(1, $ttl)]);
        $tmp = $this->directory . '/.tmp-' . bin2hex(random_bytes(8));
        [$ok, $warning] = ErrorTrap::run(static function () use ($tmp, $payload, $path): bool {
            $handle = fopen($tmp, 'x');
            if ($handle === false) {
                return false;
            }
            chmod($tmp, 0600);
            $written = fwrite($handle, (string) $payload);
            fclose($handle);
            if ($written !== strlen((string) $payload)) {
                unlink($tmp);

                return false;
            }

            return rename($tmp, $path);
        });
        if ($ok !== true) {
            if (is_file($tmp)) {
                ErrorTrap::run(static fn (): bool => unlink($tmp));
            }
            throw new StoreException('FileStore could not write an entry: ' . ($warning ?? 'unknown error'));
        }
    }

    /**
     * Remove an expired entry under its key lock, re-reading it inside the
     * lock, so a fresh value written by another process between our read
     * and the unlink is never deleted. A busy lock means someone is writing
     * it: leave it alone.
     */
    private function removeIfExpired(string $path): bool
    {
        $lockPath = substr($path, 0, -5) . '.lock';
        [$handle] = ErrorTrap::run(static fn () => fopen($lockPath, 'c'));
        if (!is_resource($handle)) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            try {
                $entry = $this->read($path);
            } catch (StoreException) {
                $entry = null;
            }
            if ($entry !== null && $entry['e'] > $this->clock->now()) {
                return false;
            }
            $this->unlink($path);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function unlink(string $path): void
    {
        if (is_file($path)) {
            ErrorTrap::run(static fn (): bool => unlink($path));
        }
    }

    /**
     * @template T
     *
     * @param callable(string): T $fn
     *
     * @return T
     */
    private function locked(string $key, callable $fn): mixed
    {
        $this->ensureDirectory();
        $path = $this->path($key);
        $lockPath = substr($path, 0, -5) . '.lock';
        [$handle, $warning] = ErrorTrap::run(static fn () => fopen($lockPath, 'c'));
        if (!is_resource($handle)) {
            throw new StoreException('FileStore could not open a lock file: ' . ($warning ?? 'unknown error'));
        }
        ErrorTrap::run(static fn (): bool => chmod($lockPath, 0600));
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new StoreException('FileStore could not lock an entry.');
            }

            return $fn($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function maybeSweep(): void
    {
        if ($this->sweepOneIn > 0 && random_int(1, $this->sweepOneIn) === 1) {
            $this->sweep();
        }
    }
}
