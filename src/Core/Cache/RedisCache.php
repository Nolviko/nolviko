<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use Closure;
use InvalidArgumentException;
use Nolviko\Core\Config\Secret;
use RuntimeException;
use stdClass;

/** Site/epoch/namespace-separated disposable cache. Authenticated envelopes reject poisoned Redis values. */
final class RedisCache implements Cache
{
    public function __construct(
        private readonly RedisConnection $connection,
        private readonly string $prefix,
        private readonly Secret $key,
        private readonly Cache $locks,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $name = $this->name($key);
        $raw = $this->connection->command(['GET', $name]);

        if (!is_string($raw) || strlen($raw) > 1048576 || strlen($raw) < 44 || !str_starts_with($raw, 'NRC1')) {
            return $default;
        }

        $payload = sodium_crypto_secretbox_open(substr($raw, 28), substr($raw, 4, 24), $this->key->reveal());

        if ($payload === false) {
            return $default;
        }

        $value = @unserialize($payload, ['allowed_classes' => false, 'max_depth' => 32]);

        if (
            !is_array($value) || ($value['name'] ?? null) !== $name || !array_key_exists('value', $value)
            || !isset($value['expires']) || !is_int($value['expires'])
            || ($value['expires'] !== 0 && $value['expires'] <= time()) || !self::valid($value['value'])
        ) {
            return $default;
        }

        return $value['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $name = $this->name($key);

        if (!self::valid($value)) {
            throw new InvalidArgumentException('cache_value_unsupported');
        }

        if ($ttl !== null && $ttl <= 0) {
            return $this->delete($key);
        }

        $payload = serialize(['name' => $name, 'expires' => $ttl === null ? 0 : time() + min($ttl, 31536000),
            'value' => $value]);

        if (strlen($payload) > 524288) {
            return false;
        }

        $nonce = random_bytes(24);
        $envelope = 'NRC1' . $nonce . sodium_crypto_secretbox($payload, $nonce, $this->key->reveal());
        $arguments = ['SET', $name, $envelope];

        if ($ttl !== null) {
            $arguments = [...$arguments, 'EX', (string) min($ttl, 31536000)];
        }

        return $this->connection->command($arguments) === 'OK';
    }

    public function delete(string $key): bool
    {
        $this->connection->command(['DEL', $this->name($key)]);

        return true;
    }

    public function has(string $key): bool
    {
        $missing = new stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function clear(): bool
    {
        // A namespace generation token invalidates only this application's namespace, never FLUSHDB.
        return $this->connection->command(['SET', $this->prefix . ':generation', bin2hex(random_bytes(16))]) === 'OK';
    }

    public function remember(string $key, ?int $ttl, Closure $compute): mixed
    {
        $missing = new stdClass();
        $value = $this->get($key, $missing);

        if ($value !== $missing) {
            return $value;
        }

        return $this->locks->remember('lock.' . hash('sha256', $key), 0, function () use ($key, $ttl, $compute): mixed {
            $missing = new stdClass();
            $value = $this->get($key, $missing);

            if ($value === $missing) {
                $value = $compute();
                $this->set($key, $value, $ttl);
            }

            return $value;
        });
    }

    private function name(string $key): string
    {
        if ($key === '' || strlen($key) > 200 || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidArgumentException('cache_key_invalid');
        }

        $generation = $this->connection->command(['GET', $this->prefix . ':generation']);

        if ($generation === null) {
            $this->connection->command(['SET', $this->prefix . ':generation', bin2hex(random_bytes(16)), 'NX']);
            $generation = $this->connection->command(['GET', $this->prefix . ':generation']);
        }

        if (!is_string($generation) || preg_match('/^[a-f0-9]{32}$/D', $generation) !== 1) {
            throw new RuntimeException('redis_generation_invalid');
        }

        return $this->prefix . ':' . $generation . ':' . hash('sha256', $key);
    }

    private static function valid(mixed $value, int $depth = 0): bool
    {
        if ($depth > 32) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::valid($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }
}
