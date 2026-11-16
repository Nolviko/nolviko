<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;
use ZipArchive;

/**
 * Inspect every ZIP entry before writing any payload: paths, aliases, modes, encryption and signed budgets.
 */
final readonly class ReleaseArchivePlan
{
    public const MAX_ENTRIES = 20000;

    public const MAX_FILE_BYTES = 16777216;

    public const MAX_COMPRESSION_RATIO = 1000;

    /**
     * @param list<array{index: int, path: string, directory: bool, size: int, crc: int}> $entries
     */
    private function __construct(public array $entries)
    {
    }

    public static function inspect(ZipArchive $archive, SignedReleaseManifest $manifest): self
    {
        if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('update_archive_entry_budget');
        }

        $entries = [];
        $paths = [];
        $directories = [];
        $bytes = 0;
        $files = 0;

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $stat = $archive->statIndex($index);

            if ($stat === false) {
                throw new RuntimeException('update_archive_metadata_invalid');
            }

            $path = ArchivePath::normalize($stat['name']);
            $directory = str_ends_with($stat['name'], '/');
            $key = strtolower($path);

            if (isset($paths[$key])) {
                throw new RuntimeException('update_archive_path_collision');
            }

            $paths[$key] = $directory;
            $operatingSystem = 0;
            $attributes = 0;

            if (!$archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                throw new RuntimeException('update_archive_metadata_invalid');
            }

            $type = ($attributes >> 16) & 0170000;

            if (
                !in_array($type, [0, 0100000, 0040000], true)
                || ($type === 0040000 && !$directory)
                || ($type === 0100000 && $directory)
                || ($stat['encryption_method'] ?? 0) !== 0
                || !in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
            ) {
                throw new RuntimeException('update_archive_entry_unsupported');
            }

            $size = $stat['size'];
            $compressed = $stat['comp_size'];

            if (
                !is_int($size)
                || !is_int($compressed)
                || $size < 0
                || $compressed < 0
                || $size > self::MAX_FILE_BYTES
                || $compressed > $manifest->archiveBytes
                || ($directory && $size !== 0)
                || $size > max(1, $compressed) * self::MAX_COMPRESSION_RATIO
            ) {
                throw new RuntimeException('update_archive_size_budget');
            }

            $bytes += $size;
            $files += $directory ? 0 : 1;

            if ($bytes > $manifest->unpackedBytes || $files > $manifest->fileCount) {
                throw new RuntimeException('update_archive_manifest_mismatch');
            }

            $entries[] = [
                'index' => $index,
                'path' => $path,
                'directory' => $directory,
                'size' => $size,
                'crc' => $stat['crc'],
            ];
        }

        if ($bytes !== $manifest->unpackedBytes || $files !== $manifest->fileCount) {
            throw new RuntimeException('update_archive_manifest_mismatch');
        }

        foreach ($entries as $entry) {
            $parts = explode('/', $entry['path']);
            array_pop($parts);
            $parent = '';

            foreach ($parts as $part) {
                $parent = $parent === '' ? $part : $parent . '/' . $part;
                $key = strtolower($parent);

                if (
                    (isset($paths[$key]) && !$paths[$key])
                    || (isset($directories[$key]) && $directories[$key] !== $parent)
                ) {
                    throw new RuntimeException('update_archive_path_collision');
                }

                $directories[$key] = $parent;

                if (count($directories) > self::MAX_ENTRIES) {
                    throw new RuntimeException('update_archive_directory_budget');
                }
            }

            if ($entry['directory']) {
                $key = strtolower($entry['path']);

                if (isset($directories[$key]) && $directories[$key] !== $entry['path']) {
                    throw new RuntimeException('update_archive_path_collision');
                }

                $directories[$key] = $entry['path'];

                if (count($directories) > self::MAX_ENTRIES) {
                    throw new RuntimeException('update_archive_directory_budget');
                }
            }
        }

        return new self($entries);
    }
}
