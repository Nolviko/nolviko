<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Closure;
use RuntimeException;

/**
 * Explicit runtime-file inventory; never recursively discovers secrets, caches, staging or backup trees.
 *
 * Format 2 (2026-11-08): small files share packed chunks of up to BackupChunkStore::MAX_CHUNK_BYTES, and a
 * file's chunk reference carries `offset` and `length` within its pack. A release has about 2,000 small files;
 * one synced chunk file each did not fit the 10-second step on slow disks, while a few hundred packs do.
 * Format 1 manifests (one chunk per piece, no offsets) are still restored and verified.
 */
final class FileBackup
{
    /**
     * @param list<string> $paths Trusted runtime inventory, relative to a fixed application root.
     * @return array{id: string, digest: string}
     */
    public static function export(BackupChunkStore $store, string $root, array $paths, Closure $guard): array
    {
        $base = realpath($root);

        if ($base === false || count($paths) > 10000) {
            throw new RuntimeException('backup_files_invalid');
        }

        self::validatePaths($paths);
        $entries = [];
        $bytes = 0;
        $deadline = microtime(true) + 10;
        $pack = '';
        $pending = [];
        $flush = static function () use ($store, &$pack, &$pending, &$entries): void {
            if ($pack === '') {
                return;
            }

            $id = bin2hex(random_bytes(16));
            $digest = $store->put($id, $pack);

            foreach ($pending as [$file, $chunk, $offset, $length]) {
                $entries[$file]['chunks'][$chunk] = ['id' => $id, 'digest' => $digest, 'offset' => $offset,
                    'length' => $length];
            }

            $pack = '';
            $pending = [];
        };

        foreach ($paths as $path) {
            $guard();
            self::safePath($base, $path);
            $source = $base . '/' . $path;

            if (!is_file($source)) {
                throw new RuntimeException('backup_source_invalid');
            }

            if (filesize($source) > 33554432) {
                throw new RuntimeException('backup_file_budget');
            }

            $before = hash_file('sha256', $source);
            $stream = fopen($source, 'rb');
            $index = count($entries);
            $entries[$index] = ['path' => $path, 'sha256' => $before, 'bytes' => 0, 'chunks' => []];
            $chunk = 0;
            $fileBytes = 0;

            try {
                while (!feof($stream)) {
                    $data = fread($stream, BackupChunkStore::MAX_CHUNK_BYTES);

                    if ($data === false) {
                        throw new RuntimeException('backup_file_read_failed');
                    }

                    if ($data === '') {
                        break;
                    }

                    $bytes += strlen($data);
                    $fileBytes += strlen($data);

                    if ($bytes > 33554432 || microtime(true) > $deadline) {
                        throw new RuntimeException('backup_file_budget');
                    }

                    if (strlen($pack) + strlen($data) > BackupChunkStore::MAX_CHUNK_BYTES) {
                        $flush();
                    }

                    $entries[$index]['chunks'][$chunk] = [];
                    $pending[] = [$index, $chunk++, strlen($pack), strlen($data)];
                    $pack .= $data;
                }
            } finally {
                fclose($stream);
            }

            self::safePath($base, $path);

            if ($before === false || $before !== hash_file('sha256', $source)) {
                throw new RuntimeException('backup_file_changed');
            }

            $entries[$index]['bytes'] = $fileBytes;
        }

        $flush();

        foreach ($entries as $entry) {
            self::safePath($base, $entry['path']);

            if ($entry['sha256'] !== hash_file('sha256', $base . '/' . $entry['path'])) {
                throw new RuntimeException('backup_file_changed');
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('backup_file_budget');
        }

        $guard();
        $id = bin2hex(random_bytes(16));
        $digest = $store->put($id, json_encode(['format' => 2, 'files' => $entries], JSON_THROW_ON_ERROR));

        return ['id' => $id, 'digest' => $digest];
    }

    /**
     * @param array{id: string, digest: string} $handle Trusted private state, never an uploaded manifest.
     */
    public static function restore(BackupChunkStore $store, array $handle, string $emptyDirectory): void
    {
        $root = realpath($emptyDirectory);

        if ($root === false || is_link($emptyDirectory) || scandir($root) !== ['.', '..']) {
            throw new RuntimeException('backup_restore_requires_empty_directory');
        }

        $manifest = json_decode($store->read($handle['id'], $handle['digest']), true, 32, JSON_THROW_ON_ERROR);

        if (!in_array($manifest['format'] ?? null, [1, 2], true) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('backup_file_manifest_invalid');
        }

        self::validatePaths(array_column($manifest['files'], 'path'));

        $cache = [];

        // Validate the entire private inventory before producing any restored file.
        foreach ($manifest['files'] as $file) {
            $hash = hash_init('sha256');
            $bytes = 0;

            foreach ($file['chunks'] as $chunk) {
                $data = self::piece($store, $chunk, $cache);
                $bytes += strlen($data);
                hash_update($hash, $data);
            }

            if ($bytes !== $file['bytes'] || !hash_equals($file['sha256'], hash_final($hash))) {
                throw new RuntimeException('backup_file_integrity_failed');
            }
        }

        foreach ($manifest['files'] as $file) {
            $target = $root . '/' . $file['path'];
            $parent = dirname($target);

            if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                throw new RuntimeException('backup_restore_directory_failed');
            }

            self::safePath($root, $file['path']);
            $stream = fopen($target, 'x+b');

            if ($stream === false) {
                throw new RuntimeException('backup_restore_file_failed');
            }

            try {
                if (!chmod($target, 0600)) {
                    throw new RuntimeException('backup_restore_permissions_failed');
                }

                foreach ($file['chunks'] as $chunk) {
                    $bytes = self::piece($store, $chunk, $cache);

                    if (fwrite($stream, $bytes) !== strlen($bytes)) {
                        throw new RuntimeException('backup_restore_write_failed');
                    }
                }

                if (!fsync($stream)) {
                    throw new RuntimeException('backup_restore_sync_failed');
                }
            } finally {
                fclose($stream);
            }
        }
    }

    /**
     * The bytes one chunk reference stands for: a whole chunk (format 1) or a slice of a pack (format 2). The
     * last pack read is kept, because neighbouring files share it.
     *
     * @param array<string, mixed> $chunk
     * @param array{id?: string, bytes?: string} $cache
     */
    public static function piece(BackupChunkStore $store, array $chunk, array &$cache): string
    {
        $id = is_string($chunk['id'] ?? null) ? $chunk['id'] : '';
        $digest = is_string($chunk['digest'] ?? null) ? $chunk['digest'] : '';

        if (($cache['id'] ?? null) !== $id) {
            $cache = ['id' => $id, 'bytes' => $store->read($id, $digest)];
        }

        $bytes = (string) $cache['bytes'];

        if (!array_key_exists('offset', $chunk)) {
            return $bytes;
        }

        $offset = $chunk['offset'];
        $length = $chunk['length'] ?? null;

        if (
            !is_int($offset) || !is_int($length) || $offset < 0 || $length < 1
            || $offset + $length > strlen($bytes)
        ) {
            throw new RuntimeException('backup_file_manifest_invalid');
        }

        return substr($bytes, $offset, $length);
    }

    /**
     * @param list<string> $paths
     */
    private static function validatePaths(array $paths): void
    {
        if (count($paths) > 10000) {
            throw new RuntimeException('backup_file_budget');
        }

        $files = [];
        $directories = [];
        $spelling = [];

        foreach ($paths as $path) {
            if (ArchivePath::normalize($path) !== $path) {
                throw new RuntimeException('backup_file_path_invalid');
            }

            if (preg_match('~^(?:storage/)?(?:_backup_[^/]*|backups?|cache|updates|staging)(?:/|$)~i', $path) === 1) {
                throw new RuntimeException('backup_recursive_source_forbidden');
            }

            $segments = explode('/', $path);
            $prefix = '';

            foreach ($segments as $index => $segment) {
                $prefix .= ($prefix === '' ? '' : '/') . $segment;
                $key = strtolower($prefix);

                if (isset($spelling[$key]) && $spelling[$key] !== $prefix) {
                    throw new RuntimeException('backup_file_collision');
                }

                $spelling[$key] = $prefix;

                if ($index === count($segments) - 1) {
                    if (isset($files[$key]) || isset($directories[$key])) {
                        throw new RuntimeException('backup_file_collision');
                    }

                    $files[$key] = true;
                } else {
                    if (isset($files[$key])) {
                        throw new RuntimeException('backup_file_collision');
                    }

                    $directories[$key] = true;
                }
            }
        }
    }

    private static function safePath(string $root, string $path): void
    {
        $current = $root;

        foreach (explode('/', $path) as $segment) {
            $current .= '/' . $segment;

            if (is_link($current)) {
                throw new RuntimeException('backup_file_link_forbidden');
            }
        }
    }
}
