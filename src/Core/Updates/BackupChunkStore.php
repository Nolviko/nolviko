<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Immutable, bounded backup chunks in an existing private directory; not a database snapshot exporter.
 */
final class BackupChunkStore
{
    public const MAX_CHUNK_BYTES = 1048576;

    private readonly string $root;

    public function __construct(string $directory, string $publicDirectory)
    {
        $root = realpath($directory);
        $public = realpath($publicDirectory);

        if ($root === false || $public === false || !is_dir($root) || !is_dir($public)) {
            throw new RuntimeException('backup_storage_invalid');
        }

        $rootKey = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $root));
        $publicKey = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $public));

        if ($rootKey === $publicKey || str_starts_with($rootKey . '/', $publicKey . '/')) {
            throw new RuntimeException('backup_storage_public');
        }

        $permissions = fileperms($root);

        if (DIRECTORY_SEPARATOR === '/' && ($permissions === false || ($permissions & 0077) !== 0)) {
            throw new RuntimeException('backup_storage_permissions');
        }

        $this->root = $root;
    }

    public function put(string $id, string $bytes): string
    {
        $path = $this->path($id);

        if (strlen($bytes) > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('backup_chunk_budget');
        }

        $digest = hash('sha256', $bytes);
        $stream = @fopen($path, 'x+b');

        if ($stream === false) {
            throw new RuntimeException('backup_chunk_exists_or_unwritable');
        }

        try {
            if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fsync($stream)) {
                throw new RuntimeException('backup_chunk_write_failed');
            }
        } finally {
            fclose($stream);
        }

        return $digest;
    }

    public function read(string $id, string $expectedDigest): string
    {
        $path = $this->path($id);

        if (preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1 || is_link($path) || !is_file($path)) {
            throw new RuntimeException('backup_chunk_invalid');
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('backup_chunk_unreadable');
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_CHUNK_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (
            $bytes === false
            || strlen($bytes) > self::MAX_CHUNK_BYTES
            || !hash_equals($expectedDigest, hash('sha256', $bytes))
        ) {
            throw new RuntimeException('backup_chunk_integrity_failed');
        }

        return $bytes;
    }

    private function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_dir($this->root) || is_link($this->root)) {
            throw new RuntimeException('backup_chunk_identity_invalid');
        }

        return $this->root . '/' . $id . '.chunk';
    }
}
