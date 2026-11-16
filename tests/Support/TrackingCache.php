<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Closure;
use Nolviko\Core\Cache\Cache;

/** Observes the real cache adapter so isolation tests prove hits and bypasses as well as rendered output. */
final class TrackingCache implements Cache
{
    public int $hits = 0;

    public int $writes = 0;

    public function __construct(private readonly Cache $inner)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);
        if ($value !== $default) {
            ++$this->hits;
        }
        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        ++$this->writes;
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function remember(string $key, ?int $ttl, Closure $compute): mixed
    {
        return $this->inner->remember($key, $ttl, $compute);
    }
}
