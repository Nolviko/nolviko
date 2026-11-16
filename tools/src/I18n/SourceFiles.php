<?php

declare(strict_types=1);

namespace Nolviko\Tools\I18n;

use Nolviko\Tools\Filesystem;

/**
 * PHP and Twig files below the given directories; missing directories are skipped.
 */
final class SourceFiles
{
    /**
     * @param list<string> $directories
     *
     * @return list<string> Sorted absolute paths.
     */
    public static function find(array $directories): array
    {
        $paths = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (Filesystem::files($directory) as $file) {
                if (in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }

    public static function lineOf(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }
}
