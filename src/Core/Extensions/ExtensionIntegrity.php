<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Bounded full-tree digest binds administrator approval to exact code bytes, rejecting links and vendor trees. */
final class ExtensionIntegrity
{
    public static function digest(string $directory): string
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new ExtensionFailure('extension_path_invalid');
        }

        $files = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            ExtensionManifest::path($relative);
            if ($file->isLink() || !$file->isFile() || str_starts_with($relative, 'vendor/')) {
                throw new ExtensionFailure('extension_path_invalid');
            }

            $bytes += $file->getSize();
            if ($bytes > 8388608 || count($files) >= 300 || $file->getSize() > 1048576) {
                throw new ExtensionFailure('extension_package_limit');
            }

            $files[$relative] = hash_file('sha256', $file->getPathname());
        }

        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
