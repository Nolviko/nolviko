<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use FilesystemIterator;
use JsonException;
use RuntimeException;
use ZipArchive;

/**
 * Bounded, restartable extraction into private quarantine. Never extracts directly into the live application.
 */
final class SafeReleaseArchive
{
    public const MAX_STEP_ENTRIES = 100;

    public const MAX_STEP_BYTES = 16777216;

    private const CHUNK_BYTES = 65536;

    private const MAX_STATE_BYTES = 4096;

    private readonly string $storage;

    public function __construct(string $storage, string $publicDirectory)
    {
        $private = realpath($storage);
        $public = realpath($publicDirectory);

        if ($private === false || $public === false || !is_dir($private) || !is_dir($public) || is_link($storage)) {
            throw new RuntimeException('update_storage_invalid');
        }

        $privateKey = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $private));
        $publicKey = rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $public)), '/');

        if ($privateKey === $publicKey || str_starts_with($privateKey, $publicKey . '/')) {
            throw new RuntimeException('update_storage_public');
        }

        $this->storage = $private;
    }

    public function step(
        string $source,
        SignedReleaseManifest $manifest,
        string $jobId,
        int $entryBudget = self::MAX_STEP_ENTRIES,
    ): ArchiveExtraction {
        if (
            preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1
            || $entryBudget < 1
            || $entryBudget > self::MAX_STEP_ENTRIES
        ) {
            throw new RuntimeException('update_extraction_request_invalid');
        }

        $root = self::directory($this->storage, 'updates');
        $job = self::directory($root, $jobId);
        $lockPath = self::regularPath($job, 'lock');
        $lock = @fopen($lockPath, 'c+b');

        if ($lock === false) {
            throw new RuntimeException('update_extraction_lock_failed');
        }

        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('update_extraction_busy');
            }

            clearstatcache();

            return $this->extract($source, $manifest, $job, $entryBudget);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function extract(
        string $source,
        SignedReleaseManifest $manifest,
        string $job,
        int $budget,
    ): ArchiveExtraction {
        $zipPath = self::regularPath($job, 'release.zip');

        if (!is_file($zipPath)) {
            self::copyArchive($source, $zipPath, $manifest);
        }

        if (
            filesize($zipPath) !== $manifest->archiveBytes
            || hash_file('sha256', $zipPath) !== $manifest->archiveHash
        ) {
            throw new RuntimeException('update_archive_identity_mismatch');
        }

        $archive = new ZipArchive();

        if ($archive->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('update_archive_invalid');
        }

        try {
            $plan = ReleaseArchivePlan::inspect($archive, $manifest);
            $stage = self::directory($job, 'stage');
            $cursor = self::cursor($job, $stage, $manifest, count($plan->entries));
            self::checkCompletedPrefix($plan, $stage, $cursor);
            $bytes = 0;
            $processed = 0;

            while ($cursor < count($plan->entries) && $processed < $budget && $bytes < self::MAX_STEP_BYTES) {
                $entry = $plan->entries[$cursor];
                $parts = explode('/', $entry['path']);
                $name = array_pop($parts);
                $parent = $stage;

                foreach ($parts as $part) {
                    $parent = self::directory($parent, $part);
                }

                if ($entry['directory']) {
                    self::directory($parent, $name);
                } else {
                    self::extractFile($archive, $entry, $job, $parent, $name);
                }

                $bytes += $entry['size'];
                ++$cursor;
                ++$processed;
            }

            self::writeCursor($job, $manifest, $cursor);

            return new ArchiveExtraction($stage, $cursor, $cursor === count($plan->entries));
        } finally {
            $archive->close();
        }
    }

    private static function checkCompletedPrefix(ReleaseArchivePlan $plan, string $stage, int $cursor): void
    {
        for ($index = 0; $index < $cursor; ++$index) {
            $entry = $plan->entries[$index];
            $path = $stage;

            foreach (explode('/', $entry['path']) as $part) {
                $path .= '/' . $part;

                if (is_link($path)) {
                    throw new RuntimeException('update_staging_path_unsafe');
                }
            }

            if ($entry['directory']) {
                if (!is_dir($path)) {
                    throw new RuntimeException('update_staged_content_missing');
                }
            } elseif (!is_file($path) || filesize($path) !== $entry['size']) {
                throw new RuntimeException('update_staged_content_missing');
            }
        }
    }

    private static function copyArchive(string $source, string $target, SignedReleaseManifest $manifest): void
    {
        if (str_contains($source, '://') || str_contains($source, "\0") || is_link($source) || !is_file($source)) {
            throw new RuntimeException('update_archive_source_invalid');
        }

        $input = @fopen($source, 'rb');

        if ($input === false) {
            throw new RuntimeException('update_archive_source_invalid');
        }

        $temporary = self::regularPath(dirname($target), 'archive.part');
        self::removePartial($temporary);
        $output = @fopen($temporary, 'xb');

        if ($output === false) {
            fclose($input);

            throw new RuntimeException('update_archive_write_failed');
        }

        try {
            $hash = hash_init('sha256');
            $bytes = 0;

            while (!feof($input)) {
                $chunk = @fread($input, self::CHUNK_BYTES);

                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException('update_archive_read_failed');
                }

                $bytes += strlen($chunk);

                if ($bytes > $manifest->archiveBytes) {
                    throw new RuntimeException('update_archive_identity_mismatch');
                }

                hash_update($hash, $chunk);
                self::writeAll($output, $chunk);
            }

            if ($bytes !== $manifest->archiveBytes || hash_final($hash) !== $manifest->archiveHash) {
                throw new RuntimeException('update_archive_identity_mismatch');
            }

            if (!chmod($temporary, 0600) || !fflush($output) || !fsync($output)) {
                throw new RuntimeException('update_archive_write_failed');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if (!rename($temporary, $target)) {
            throw new RuntimeException('update_archive_write_failed');
        }
    }

    /**
     * @param array{index: int, path: string, directory: bool, size: int, crc: int} $entry
     */
    private static function extractFile(
        ZipArchive $archive,
        array $entry,
        string $job,
        string $parent,
        string $name,
    ): void {
        $target = self::regularPath($parent, $name);
        $temporary = self::regularPath($job, 'current.part');
        self::removePartial($temporary);
        $input = @$archive->getStreamIndex($entry['index']);

        if ($input === false) {
            throw new RuntimeException('update_archive_read_failed');
        }

        $output = @fopen($temporary, 'xb');

        if ($output === false) {
            fclose($input);

            throw new RuntimeException('update_archive_write_failed');
        }

        try {
            $crc = hash_init('crc32b');
            $bytes = 0;

            while (!feof($input)) {
                $chunk = @fread($input, self::CHUNK_BYTES);

                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException('update_archive_read_failed');
                }

                $bytes += strlen($chunk);

                if ($bytes > $entry['size']) {
                    throw new RuntimeException('update_archive_size_budget');
                }

                hash_update($crc, $chunk);
                self::writeAll($output, $chunk);
            }

            if ($bytes !== $entry['size'] || hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
                throw new RuntimeException('update_archive_content_mismatch');
            }

            if (!chmod($temporary, 0600) || !fflush($output) || !fsync($output)) {
                throw new RuntimeException('update_archive_write_failed');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if (!rename($temporary, $target)) {
            throw new RuntimeException('update_archive_write_failed');
        }
    }

    private static function directory(string $parent, string $name): string
    {
        $path = $parent . '/' . $name;

        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('update_staging_path_unsafe');
        }

        if (!is_dir($path) && !mkdir($path, 0700)) {
            throw new RuntimeException('update_staging_directory_failed');
        }

        if (!chmod($path, 0700)) {
            throw new RuntimeException('update_staging_directory_failed');
        }

        return $path;
    }

    private static function regularPath(string $directory, string $name): string
    {
        $path = $directory . '/' . $name;

        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('update_staging_path_unsafe');
        }

        return $path;
    }

    private static function cursor(string $job, string $stage, SignedReleaseManifest $manifest, int $count): int
    {
        $file = self::regularPath($job, 'progress.json');

        if (!is_file($file)) {
            if ((new FilesystemIterator($stage, FilesystemIterator::SKIP_DOTS))->valid()) {
                throw new RuntimeException('update_extraction_state_missing');
            }

            self::writeCursor($job, $manifest, 0);

            return 0;
        }

        if (filesize($file) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('update_extraction_state_invalid');
        }

        try {
            $state = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_extraction_state_invalid');
        }

        if (
            !is_array($state)
            || array_keys($state) !== ['manifest_hash', 'next_index']
            || $state['manifest_hash'] !== $manifest->manifestHash
            || !is_int($state['next_index'])
            || $state['next_index'] < 0
            || $state['next_index'] > $count
        ) {
            throw new RuntimeException('update_extraction_state_invalid');
        }

        return $state['next_index'];
    }

    private static function writeCursor(string $job, SignedReleaseManifest $manifest, int $cursor): void
    {
        $target = self::regularPath($job, 'progress.json');
        $temporary = self::regularPath($job, 'progress.part');
        self::removePartial($temporary);
        $output = @fopen($temporary, 'xb');

        if ($output === false) {
            throw new RuntimeException('update_extraction_state_write_failed');
        }

        try {
            self::writeAll($output, json_encode([
                'manifest_hash' => $manifest->manifestHash,
                'next_index' => $cursor,
            ], JSON_THROW_ON_ERROR));

            if (!chmod($temporary, 0600) || !fflush($output) || !fsync($output)) {
                throw new RuntimeException('update_extraction_state_write_failed');
            }
        } finally {
            fclose($output);
        }

        if (!rename($temporary, $target)) {
            throw new RuntimeException('update_extraction_state_write_failed');
        }
    }

    private static function removePartial(string $file): void
    {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('update_partial_cleanup_failed');
        }
    }

    /**
     * @param resource $stream
     */
    private static function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $written = @fwrite($stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('update_archive_write_failed');
            }

            $offset += $written;
        }
    }
}
