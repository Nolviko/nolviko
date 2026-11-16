<?php

declare(strict_types=1);

namespace Nolviko\Core\Cache;

use Nolviko\Core\Config\Secret;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Atomic private encrypted settings. Failed preflight never replaces the last active configuration. */
final class RedisSettingsStore
{
    public function __construct(private readonly string $storage)
    {
    }

    public function status(): array
    {
        $state = $this->read();

        return ['enabled' => ($state['enabled'] ?? false) === true,
            'epoch' => $state['epoch'] ?? 'filesystem', 'reason' => $state['reason'] ?? null,
            'namespaces' => $state['namespaces'] ?? []];
    }

    public function options(): ?RedisOptions
    {
        $state = $this->read();

        if (($state['enabled'] ?? false) !== true) {
            return null;
        }

        return new RedisOptions(
            $state['host'],
            $state['port'],
            $state['password'],
            $state['username'],
            $state['database'],
            $state['tls'],
            $state['peer_name'],
            $state['namespaces']
        );
    }

    public function preflight(RedisOptions $options): void
    {
        $connection = new RedisConnection($options);
        $key = 'nolviko:preflight:' . bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));

        try {
            if (
                $connection->command(['PING']) !== 'PONG'
                || $connection->command(['SET', $key, $value, 'EX', '5']) !== 'OK'
                || $connection->command(['GET', $key]) !== $value
            ) {
                throw new RuntimeException('redis_preflight_failed');
            }
        } finally {
            try {
                $connection->command(['DEL', $key]);
            } catch (Throwable) {
                // The probe has a five-second TTL even when deletion cannot reach the service.
            }
        }
    }

    public function activate(RedisOptions $options): void
    {
        $this->preflight($options);
        $lock = $this->lock($this->directory());

        try {
            $this->write(['enabled' => true, 'epoch' => bin2hex(random_bytes(16)), 'reason' => null,
                'host' => $options->host, 'port' => $options->port, 'username' => $options->username,
                'password' => $options->password->reveal(), 'database' => $options->database,
                'tls' => $options->tls, 'peer_name' => $options->peerName, 'namespaces' => $options->namespaces]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function disable(?string $expectedEpoch = null): void
    {
        $directory = $this->directory();
        $lock = $this->lock($directory);

        try {
            $state = $this->read();

            if ($expectedEpoch !== null && ($state['epoch'] ?? null) !== $expectedEpoch) {
                return;
            }

            $this->write(['enabled' => false, 'epoch' => bin2hex(random_bytes(16)),
                'reason' => $expectedEpoch === null ? null : 'redis_connection_failed', 'namespaces' => []]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function key(): Secret
    {
        $directory = $this->directory();
        $path = $directory . '/key';

        if (is_link($path)) {
            throw new RuntimeException('redis_settings_invalid');
        }

        if (!is_file($path)) {
            $stream = @fopen($path, 'xb');

            if (is_resource($stream)) {
                try {
                    chmod($path, 0600);

                    if (fwrite($stream, random_bytes(32)) !== 32 || !fflush($stream)) {
                        throw new RuntimeException('redis_settings_invalid');
                    }
                } finally {
                    fclose($stream);
                }
            }
        }

        $bytes = @file_get_contents($path, false, null, 0, 33);

        if (!is_string($bytes) || strlen($bytes) !== 32) {
            throw new RuntimeException('redis_settings_invalid');
        }

        return new Secret($bytes);
    }

    private function read(): array
    {
        $path = $this->storage . '/redis/settings';

        if (!file_exists($path)) {
            return [];
        }

        try {
            if (is_link($this->storage . '/redis') || is_link($path) || filesize($path) > 16384) {
                throw new RuntimeException('redis_settings_invalid');
            }

            $bytes = file_get_contents($path);
            $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(
                substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $nonce,
                $this->key()->reveal()
            );

            if ($plain === false) {
                throw new RuntimeException('redis_settings_invalid');
            }

            $state = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);

            if (
                !is_array($state) || !is_bool($state['enabled'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/D', $state['epoch'] ?? '') !== 1
            ) {
                throw new RuntimeException('redis_settings_invalid');
            }

            return $state;
        } catch (Throwable) {
            // Damaged settings are a disabled cache, never a broken forum or guessed plaintext credentials.
            return ['enabled' => false, 'epoch' => 'invalid', 'reason' => 'redis_settings_invalid', 'namespaces' => []];
        }
    }

    private function write(#[SensitiveParameter] array $state): void
    {
        $directory = $this->directory();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $bytes = $nonce . sodium_crypto_secretbox(
            json_encode($state, JSON_THROW_ON_ERROR),
            $nonce,
            $this->key()->reveal()
        );
        $temporary = $directory . '/pending-' . bin2hex(random_bytes(8));

        if (
            file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($temporary, 0600)
            || is_link($directory . '/settings') || !rename($temporary, $directory . '/settings')
        ) {
            @unlink($temporary);
            throw new RuntimeException('redis_settings_unavailable');
        }
    }

    private function directory(): string
    {
        $root = realpath($this->storage);
        $directory = $this->storage . '/redis';

        if (
            $root === false || is_link($this->storage) || is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0700))
        ) {
            throw new RuntimeException('redis_settings_unavailable');
        }

        return $directory;
    }

    private function lock(string $directory): mixed
    {
        if (is_link($directory . '/lock')) {
            throw new RuntimeException('redis_settings_invalid');
        }

        $stream = fopen($directory . '/lock', 'c+b');

        if (!is_resource($stream) || !flock($stream, LOCK_EX)) {
            throw new RuntimeException('redis_settings_unavailable');
        }

        chmod($directory . '/lock', 0600);

        return $stream;
    }
}
