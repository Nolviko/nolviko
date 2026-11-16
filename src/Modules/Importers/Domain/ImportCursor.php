<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

use RuntimeException;

/** Lexically ordered, lossless one-to-three positive signed-64-bit source keys, including composite PM/vote IDs. */
final class ImportCursor
{
    public static function key(array $parts): string
    {
        if ($parts === [] || count($parts) > 3) {
            throw new RuntimeException('import_cursor_invalid');
        }
        foreach ($parts as $part) {
            if (!is_int($part) || $part < 0) {
                throw new RuntimeException('import_cursor_invalid');
            }
        }
        return implode(':', array_map(static fn(int $id): string => str_pad(
            (string) $id,
            19,
            '0',
            STR_PAD_LEFT
        ), $parts));
    }

    public static function parts(string $key, int $arity = 1): array
    {
        if ($arity < 1 || $arity > 3) {
            throw new RuntimeException('import_cursor_invalid');
        }
        if ($key === '') {
            return array_fill(0, $arity, 0);
        }
        $parts = explode(':', $key);
        if (count($parts) !== $arity) {
            throw new RuntimeException('import_cursor_invalid');
        }
        foreach ($parts as &$part) {
            if (preg_match('/^[0-9]{19}$/D', $part) !== 1 || strcmp($part, (string) PHP_INT_MAX) > 0) {
                throw new RuntimeException('import_cursor_invalid');
            }
            $part = (int) $part;
        }
        unset($part);
        return $parts;
    }
}
