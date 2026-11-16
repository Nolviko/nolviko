<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cache in `<storage>/cache/data/<namespace>/`, one file per key (ADR-009 default adapter).
 *
 * File format: a header line `FECACHE1 <expires-unix|0> <sha256 of payload>` and a `serialize()` payload read
 * with `allowed_classes => false`. Writes go to a temporary file in the same directory and are renamed over
 * the entry, so a reader sees the old or the new file, never a torn one; a header or checksum mismatch is a
 * miss and the file is removed. Directories are 0700 and files 0600 where the host allows.
 */
final class FileCache implements Cache
{
    public const NAMESPACE_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';

    private const MAGIC = 'FECACHE1';

    private const MAX_KEY_LENGTH = 200;

    private const RESERVED_CHARACTERS = '{}()/\\@:';

    private const DIRECTORY_MODE = 0700;

    private const FILE_MODE = 0600;

    private readonly Closure $clock;

    private readonly string $createBelow;

    /**
     * @param string|null $directory The namespace directory; null (no storage) makes every call a miss.
     * @param string|null $createBelow Existing directory below which missing directories may be created
     *     (the storage directory); null means the namespace directory's parent.
     * @param (Closure(): int)|null $clock Unix time; null means time().
     */
    public function __construct(
        private readonly ?string $directory,
        ?string $createBelow = null,
        ?Closure $clock = null,
    ) {
        $this->createBelow = $createBelow ?? dirname((string) $directory);
        $this->clock = $clock ?? time(...);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        $entry = $file === null ? null : $this->read($file);

        return $entry === null ? $default : $entry[0];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->file($key);
        self::assertStorable($value);

        if ($ttl !== null && $ttl <= 0) {
            return $this->delete($key);
        }

        if ($file === null || !$this->ensureDirectory(dirname($file))) {
            return false;
        }

        $payload = serialize($value);
        $expires = $ttl === null ? 0 : ($this->clock)() + $ttl;
        $content = self::MAGIC . ' ' . $expires . ' ' . hash('sha256', $payload) . "\n" . $payload;
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $content) !== strlen($content)) {
            @unlink($temporary);

            return false;
        }

        @chmod($temporary, self::FILE_MODE);

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->file($key);

        return $file === null || !is_file($file) || @unlink($file) || !is_file($file);
    }

    public function has(string $key): bool
    {
        $file = $this->file($key);

        return $file !== null && $this->read($file) !== null;
    }

    public function clear(): bool
    {
        if ($this->directory === null || !is_dir($this->directory)) {
            return true;
        }

        $trash = $this->directory . '.trash-' . bin2hex(random_bytes(6));

        if (!@rename($this->directory, $trash)) {
            return false;
        }

        self::removeTree($trash);

        return true;
    }

    public function remember(string $key, ?int $ttl, Closure $compute): mixed
    {
        $file = $this->file($key);
        $entry = $file === null ? null : $this->read($file);

        if ($entry !== null) {
            return $entry[0];
        }

        $lock = $file === null ? false : $this->lock($file);

        try {
            // Whoever held the lock before us may have stored the value meanwhile.
            $entry = $lock === false ? null : $this->read((string) $file);

            if ($entry !== null) {
                return $entry[0];
            }

            $value = $compute();
            $this->set($key, $value, $ttl);

            return $value;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return array{0: mixed}|null The value wrapped (a cached null or false is still a hit), or null on a miss.
     */
    private function read(string $file): ?array
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            return null;
        }

        [$header, $payload] = array_pad(explode("\n", $content, 2), 2, null);
        $fields = explode(' ', (string) $header);

        if (
            $payload === null
            || count($fields) !== 3
            || $fields[0] !== self::MAGIC
            || !ctype_digit($fields[1])
            || !hash_equals($fields[2], hash('sha256', $payload))
        ) {
            @unlink($file);

            return null;
        }

        if ($fields[1] !== '0' && (int) $fields[1] <= ($this->clock)()) {
            return null;
        }

        $value = @unserialize($payload, ['allowed_classes' => false]);

        // A forged payload with an object comes back as __PHP_Incomplete_Class: treat it like corruption.
        if (($value === false && $payload !== serialize(false)) || !self::isStorable($value)) {
            @unlink($file);

            return null;
        }

        return [$value];
    }

    /**
     * @return resource|false An exclusive lock on the entry's lock file, or false when locking is impossible.
     */
    private function lock(string $file): mixed
    {
        if (!$this->ensureDirectory(dirname($file))) {
            return false;
        }

        $handle = @fopen($file . '.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    private function file(string $key): ?string
    {
        if (
            $key === ''
            || strlen($key) > self::MAX_KEY_LENGTH
            || strpbrk($key, self::RESERVED_CHARACTERS) !== false
        ) {
            throw new InvalidArgumentException('cache_key_invalid');
        }

        if ($this->directory === null) {
            return null;
        }

        $hash = hash('sha256', $key);

        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    /**
     * Creates the namespace directories below an existing parent only; a missing storage directory stays
     * missing (it is created by the installer with the right owner, never by a cache write).
     */
    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (!is_dir($this->createBelow) || !str_starts_with($directory, $this->createBelow . '/')) {
            return false;
        }

        if (!@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            return false;
        }

        for ($path = $directory; strlen($path) > strlen($this->createBelow); $path = dirname($path)) {
            @chmod($path, self::DIRECTORY_MODE);
        }

        return true;
    }

    private static function assertStorable(mixed $value): void
    {
        if (!self::isStorable($value)) {
            throw new InvalidArgumentException('cache_value_unsupported');
        }
    }

    private static function isStorable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isStorable($item)) {
                    return false;
                }
            }

            return true;
        }

        return !is_object($value) && !is_resource($value);
    }

    private static function removeTree(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
