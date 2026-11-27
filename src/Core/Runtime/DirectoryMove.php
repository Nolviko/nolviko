<?php

declare(strict_types=1);

namespace Nolviko\Core\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Moves a folder (2026-11-27, shared by extension and theme installs): `rename()` first, and a copy when the source
 * and target are on different disks (for example a storage volume in Docker), where `rename()` fails.
 */
final class DirectoryMove
{
    public static function move(string $source, string $target): bool
    {
        return @rename($source, $target) || (is_dir($source) && !is_link($source) && !file_exists($target)
            && self::copyTree($source, $target));
    }

    /** Removes a folder and everything in it; links are removed, never followed. */
    public static function removeTree(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            @unlink($directory);

            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    /** Copies a folder, then removes the source; a failed copy removes the half-written target instead. */
    private static function copyTree(string $source, string $target): bool
    {
        $ok = @mkdir($target, 0700, true);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($ok ? $items : [] as $item) {
            $path = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isLink()) {
                $ok = false;
            } elseif ($item->isDir()) {
                $ok = is_dir($path) || @mkdir($path, 0700);
            } else {
                $ok = @copy($item->getPathname(), $path);
            }
            if (!$ok) {
                break;
            }
        }
        self::removeTree($ok ? $source : $target);

        return $ok;
    }
}
