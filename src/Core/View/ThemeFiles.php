<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Bounded source inventory shared by build/export; symlinks and non-theme executable files fail closed. */
final class ThemeFiles
{
    public static function read(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new TemplateException('theme_path_invalid');
        }
        $files = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                throw new TemplateException('theme_path_invalid');
            }
            $file = ThemeCatalog::contained($directory, $entry->getPathname());
            $name = str_replace('\\', '/', substr($file, strlen((string) realpath($directory)) + 1));
            if (basename($name) === '.htaccess') {
                continue;
            }
            self::assertName($name);
            if (count($files) >= 300 || $entry->getSize() > 262144 || ($bytes += $entry->getSize()) > 8388608) {
                throw new TemplateException('theme_package_limit');
            }
            $body = file_get_contents($file);
            if (!is_string($body) || strlen($body) > 262144 || !mb_check_encoding($body, 'UTF-8')) {
                throw new TemplateException('theme_file_invalid');
            }
            $files[$name] = $body;
        }
        ksort($files);
        return $files;
    }

    public static function assertName(string $name): void
    {
        if (
            $name === 'theme.json' || $name === 'tokens.json'
            || (str_starts_with($name, 'views/') && ThemeManifest::templateName(substr($name, 6)))
            || preg_match('~^assets/[a-z][a-z0-9.-]{0,79}\.(css|js)$~D', $name) === 1
        ) {
            if (!str_contains($name, '..')) {
                return;
            }
        }
        throw new TemplateException('theme_path_invalid');
    }

    public static function core(string $views): array
    {
        // Reuse the same traversal limits by treating core templates as a views-only tree.
        $files = [];
        $bytes = 0;
        foreach (
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $views,
                RecursiveDirectoryIterator::SKIP_DOTS,
            )) as $entry
        ) {
            if ($entry->isLink()) {
                throw new TemplateException('theme_path_invalid');
            }
            if (!$entry->isFile() || basename($entry->getPathname()) === '.htaccess') {
                continue;
            }
            $file = ThemeCatalog::contained($views, $entry->getPathname());
            $name = str_replace('\\', '/', substr($file, strlen((string) realpath($views)) + 1));
            if (
                !ThemeManifest::templateName($name) || count($files) >= 300 || $entry->getSize() > 262144
                || ($bytes += $entry->getSize()) > 8388608
            ) {
                throw new TemplateException('theme_package_limit');
            }
            $files[$name] = (string) file_get_contents($file);
        }
        ksort($files);
        return $files;
    }

    public static function fingerprint(array $files): string
    {
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
