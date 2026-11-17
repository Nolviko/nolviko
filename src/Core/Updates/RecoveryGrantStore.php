<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Closure;
use RuntimeException;
use SensitiveParameter;

/** Private short-lived capabilities. Issuance requires a freshly authenticated administrator at the caller. */
final class RecoveryGrantStore
{
    private readonly string $root;

    public function __construct(string $directory, string $publicDirectory)
    {
        $root = realpath($directory);
        $public = realpath($publicDirectory);

        if ($root === false || $public === false || !is_dir($root) || !is_dir($public) || is_link($directory)) {
            throw new RuntimeException('recovery_storage_invalid');
        }

        $key = rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $root)), '/');
        $published = rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $public)), '/');

        if (
            str_starts_with($key . '/', $published . '/')
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($root) & 0077) !== 0)
        ) {
            throw new RuntimeException('recovery_storage_not_private');
        }

        $this->root = $root;
    }

    /**
     * @return array{id: string, code: string, nonce: string, expires_at: int, sequence: int}
     */
    public function issue(string $job, int $actor, int $seconds = 600): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $actor < 1 || $seconds < 60 || $seconds > 900) {
            throw new RuntimeException('recovery_grant_policy_invalid');
        }

        $id = bin2hex(random_bytes(16));
        $code = self::secret();
        $nonce = self::secret();
        $expires = time() + $seconds;
        $stream = fopen($this->root . '/' . $id . '.json', 'x+b');

        if ($stream === false) {
            throw new RuntimeException('recovery_storage_failed');
        }

        try {
            if (!chmod($this->root . '/' . $id . '.json', 0600)) {
                throw new RuntimeException('recovery_storage_failed');
            }

            self::save($stream, [
                'format' => 1,
                'job' => $job,
                'actor' => $actor,
                'expires' => $expires,
                // Sliding (2026-11-17): every accepted command renews the window, so a long backup or file switch
                // driven by the one-click page never outlives its grant; an idle grant still expires.
                'window' => $seconds,
                'code' => hash('sha256', $code),
                'nonce' => hash('sha256', $nonce),
                'sequence' => 0,
                'revoked' => false,
            ]);
        } finally {
            fclose($stream);
        }

        return ['id' => $id, 'code' => $code, 'nonce' => $nonce, 'expires_at' => $expires, 'sequence' => 0];
    }

    /**
     * @param Closure(int): bool $authorize Must check the CURRENT authenticated actor and administrator privilege.
     * @param Closure(string, int): array<string, mixed> $operation Fixed server-owned command for the bound job.
     * @return array{sequence: int, conflict: bool, result: array<string, mixed>}
     */
    public function useGrant(
        string $id,
        #[SensitiveParameter]
        string $code,
        #[SensitiveParameter]
        string $nonce,
        int $sequence,
        Closure $authorize,
        Closure $operation,
        bool $revoke = false,
    ): array {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || strlen($code) !== 43 || strlen($nonce) !== 43) {
            throw new RuntimeException('recovery_grant_refused');
        }

        $path = $this->root . '/' . $id . '.json';

        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('recovery_grant_refused');
        }

        $stream = @fopen($path, 'r+b');

        if ($stream === false) {
            throw new RuntimeException('recovery_grant_refused');
        }

        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('recovery_grant_busy');
            }

            $bytes = stream_get_contents($stream, 4097);
            $state = is_string($bytes) && strlen($bytes) <= 4096 ? json_decode($bytes, true) : null;

            if (
                !is_array($state) || ($state['format'] ?? null) !== 1 || ($state['revoked'] ?? true) !== false
                || !is_int($state['expires'] ?? null) || $state['expires'] <= time()
                || !is_int($state['actor'] ?? null) || $state['actor'] < 1
                || !is_string($state['job'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['job']) !== 1
                || !is_string($state['code'] ?? null) || !is_string($state['nonce'] ?? null)
                || !hash_equals($state['code'], hash('sha256', $code))
                || !hash_equals($state['nonce'], hash('sha256', $nonce))
                || !is_int($state['sequence'] ?? null) || $state['sequence'] < 0 || $state['sequence'] >= 1000000
                || $authorize($state['actor']) !== true
            ) {
                throw new RuntimeException('recovery_grant_refused');
            }

            if ($sequence !== $state['sequence']) {
                return ['sequence' => $state['sequence'], 'conflict' => true, 'result' => []];
            }

            ++$state['sequence'];
            $state['revoked'] = $revoke;
            $window = $state['window'] ?? null;
            if (is_int($window) && $window >= 60 && $window <= 900) {
                $state['expires'] = max($state['expires'], time() + $window);
            }
            // Consume before dispatch: after a crash the same browser request must never execute twice.
            self::save($stream, $state);
            $result = $revoke ? [] : $operation($state['job'], $state['actor']);

            return ['sequence' => $state['sequence'], 'conflict' => false, 'result' => $result];
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     * @param array<string, mixed> $state
     */
    private static function save(mixed $stream, array $state): void
    {
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);

        if (
            !rewind($stream)
            || !ftruncate($stream, 0)
            || fwrite($stream, $bytes) !== strlen($bytes)
            || !fsync($stream)
        ) {
            throw new RuntimeException('recovery_storage_failed');
        }
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
