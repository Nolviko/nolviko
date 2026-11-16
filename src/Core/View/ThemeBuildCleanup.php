<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Only our fresh stage/import directory is eligible; never an installed theme, published build or link. */
final class ThemeBuildCleanup
{
    public static function remove(string $root, string $stage): void
    {
        if (!is_dir($stage)) {
            return;
        }
        $real = ThemeCatalog::contained($root, $stage);
        if (is_link($stage) || preg_match('/^(stage|import)-[a-f0-9]{24}$/D', basename($real)) !== 1) {
            throw new TemplateException('theme_path_invalid');
        }
        foreach (
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $real,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ), RecursiveIteratorIterator::CHILD_FIRST) as $entry
        ) {
            if ($entry->isLink()) {
                unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($real);
    }
}
