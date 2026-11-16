<?php

declare(strict_types=1);

namespace Nolviko\Tools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Filesystem helpers shared by the development and packaging tools (never shipped in a release).
 */
final class Filesystem
{
    public const DIRECTORY_MODE = 0755;

    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, self::DIRECTORY_MODE, true);
        }
    }

    /**
     * Copy a file, creating the target directory, and verify the copy by SHA-256.
     *
     * @throws RuntimeException With $failureMessage when the copy fails or differs from the source.
     */
    public static function copyVerified(string $source, string $target, string $failureMessage): void
    {
        self::ensureDirectory(dirname($target));

        if (!copy($source, $target) || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
            throw new RuntimeException($failureMessage);
        }
    }

    /**
     * Every leaf entry below a directory: regular files, plus links and other non-traversed entries.
     *
     * @return iterable<SplFileInfo>
     */
    public static function leaves(string $directory): iterable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    public static function files(string $directory): iterable
    {
        foreach (self::leaves($directory) as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    public static function relativePath(string $base, string $path): string
    {
        return substr($path, strlen($base) + 1);
    }
}
