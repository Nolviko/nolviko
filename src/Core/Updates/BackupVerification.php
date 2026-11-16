<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Read-only recovery preflight: every private chunk must remain available before any live-file mutation.
 */
final class BackupVerification
{
    /**
     * @param array{id: string, digest: string} $handle
     * @param array<string, string> $required Owned files about to be replaced/removed => original release hashes.
     */
    public static function verify(BackupChunkStore $store, array $handle, array $required): void
    {
        $manifest = json_decode($store->read($handle['id'], $handle['digest']), true, 32, JSON_THROW_ON_ERROR);

        if (
            ($manifest['format'] ?? null) !== 1 || !is_array($manifest['tables'] ?? null)
            || count($manifest['tables']) > 256 || !is_array($manifest['files'] ?? null)
        ) {
            throw new RuntimeException('update_backup_verification_failed');
        }

        $totalBytes = 0;
        $totalRows = 0;

        foreach ($manifest['tables'] as $table) {
            $bytes = 0;
            $rows = 0;

            foreach ($table['chunks'] as $chunk) {
                $data = $store->read($chunk['id'], $chunk['digest']);
                $bytes += strlen($data);
                $rows += substr_count($data, "\n");

                if ($bytes + $totalBytes > 33554432 || $rows + $totalRows > 100000) {
                    throw new RuntimeException('update_backup_verification_failed');
                }
            }

            if ($bytes !== $table['bytes'] || $rows !== $table['rows']) {
                throw new RuntimeException('update_backup_verification_failed');
            }

            $totalBytes += $bytes;
            $totalRows += $rows;
        }

        if ($totalBytes !== $manifest['bytes'] || $totalRows !== $manifest['rows']) {
            throw new RuntimeException('update_backup_verification_failed');
        }

        $filesHandle = $manifest['files'];
        $files = json_decode($store->read($filesHandle['id'], $filesHandle['digest']), true, 32, JSON_THROW_ON_ERROR);

        if (
            !in_array($files['format'] ?? null, [1, 2], true) || !is_array($files['files'] ?? null)
            || count($files['files']) > 10000
        ) {
            throw new RuntimeException('update_backup_verification_failed');
        }

        $found = [];
        $total = 0;
        $cache = [];

        foreach ($files['files'] as $file) {
            $hash = hash_init('sha256');
            $bytes = 0;

            foreach ($file['chunks'] as $chunk) {
                $data = FileBackup::piece($store, $chunk, $cache);
                $bytes += strlen($data);
                $total += strlen($data);

                if ($total > 33554432) {
                    throw new RuntimeException('update_backup_verification_failed');
                }

                hash_update($hash, $data);
            }

            if ($bytes !== $file['bytes'] || hash_final($hash) !== $file['sha256'] || isset($found[$file['path']])) {
                throw new RuntimeException('update_backup_verification_failed');
            }

            $found[$file['path']] = $file['sha256'];
        }

        foreach ($required as $path => $hash) {
            if (($found[$path] ?? null) !== $hash) {
                throw new RuntimeException('update_backup_file_missing_or_modified');
            }
        }
    }
}
