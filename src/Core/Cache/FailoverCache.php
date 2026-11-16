<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use Closure;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** A failed remote epoch is disabled persistently; reactivation uses fresh namespaces instead of stale data. */
final class FailoverCache implements Cache
{
    private ?Cache $fallback = null;

    public function __construct(
        private readonly Cache $remote,
        private readonly RedisSettingsStore $settings,
        private readonly string $epoch,
        private readonly Closure $local,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->call(static fn(Cache $cache): mixed => $cache->get($key, $default));
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->call(static fn(Cache $cache): bool => $cache->set($key, $value, $ttl));
    }

    public function delete(string $key): bool
    {
        return $this->call(static fn(Cache $cache): bool => $cache->delete($key));
    }

    public function has(string $key): bool
    {
        return $this->call(static fn(Cache $cache): bool => $cache->has($key));
    }

    public function clear(): bool
    {
        return $this->call(static fn(Cache $cache): bool => $cache->clear());
    }

    public function remember(string $key, ?int $ttl, Closure $compute): mixed
    {
        $missing = new stdClass();
        $value = $this->get($key, $missing);

        if ($value !== $missing) {
            return $value;
        }

        $locks = ($this->local)($this->epoch . '.remember');

        return $locks->remember('lock.' . hash('sha256', $key), 0, function () use ($key, $ttl, $compute): mixed {
            $missing = new stdClass();
            $value = $this->get($key, $missing);

            if ($value === $missing) {
                // Business exceptions are outside transport catches and must never cause a second computation.
                $value = $compute();
                $this->set($key, $value, $ttl);
            }

            return $value;
        });
    }

    private function call(Closure $operation): mixed
    {
        if ($this->fallback !== null) {
            return $operation($this->fallback);
        }

        $state = $this->settings->status();

        if (!$state['enabled'] || $state['epoch'] !== $this->epoch) {
            $this->fallback = ($this->local)($state['epoch']);

            return $operation($this->fallback);
        }

        try {
            return $operation($this->remote);
        } catch (InvalidArgumentException $failure) {
            throw $failure;
        } catch (Throwable) {
            try {
                $this->settings->disable($this->epoch);
            } catch (Throwable) {
                // A read-only settings directory still allows this request to use an uncached local path.
            }

            $this->fallback = ($this->local)($this->settings->status()['epoch'] . '.failed');

            return $operation($this->fallback);
        }
    }
}
