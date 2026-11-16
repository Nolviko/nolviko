<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use Closure;
use InvalidArgumentException;

/**
 * Application-owned cache contract in the shape of PSR-16 (psr/simple-cache is not a dependency), scoped to one
 * namespace. Values are scalars, null and arrays of them; objects are refused so a cache file can never
 * instantiate a class. A cache is an optimisation: every failure (unwritable, corrupt, missing) is a miss.
 */
interface Cache
{
    /**
     * @throws InvalidArgumentException `cache_key_invalid` for an empty, over-long or PSR-16-reserved key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param int|null $ttl Seconds; null keeps the value until cleared, zero or less deletes it (PSR-16).
     *
     * @return bool False when it could not be stored (no storage, disk error); the caller just continues.
     *
     * @throws InvalidArgumentException `cache_key_invalid` or `cache_value_unsupported`.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    /**
     * Removes every entry of this namespace at once (readers then see misses, never a half-cleared state).
     */
    public function clear(): bool;

    /**
     * The cached value, or compute it once: concurrent callers wait for the first one instead of all
     * recomputing (stampede protection).
     *
     * @template T
     *
     * @param Closure(): T $compute
     *
     * @return T
     */
    public function remember(string $key, ?int $ttl, Closure $compute): mixed;
}
