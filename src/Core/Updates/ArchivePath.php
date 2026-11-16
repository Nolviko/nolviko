<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Portable, non-ambiguous release paths; no URL decoding or filesystem normalization of archive names.
 */
final class ArchivePath
{
    public const MAX_BYTES = 240;

    public const MAX_DEPTH = 16;

    public static function normalize(string $name): string
    {
        $path = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;

        if (
            $path === ''
            || strlen($path) > self::MAX_BYTES
            || preg_match('~^[A-Za-z0-9_.@+-]+(?:/[A-Za-z0-9_.@+-]+)*$~D', $path) !== 1
        ) {
            throw new RuntimeException('update_archive_path_invalid');
        }

        $segments = explode('/', $path);

        if (count($segments) > self::MAX_DEPTH) {
            throw new RuntimeException('update_archive_path_invalid');
        }

        foreach ($segments as $segment) {
            if (
                $segment === '.'
                || $segment === '..'
                || str_ends_with($segment, '.')
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iD', $segment) === 1
            ) {
                throw new RuntimeException('update_archive_path_invalid');
            }
        }

        return $path;
    }
}
