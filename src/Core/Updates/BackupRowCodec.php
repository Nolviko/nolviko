<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use JsonException;
use RuntimeException;

/**
 * Canonical binary-safe row framing. SQL adapters must supply strings or NULL without numeric coercion.
 */
final class BackupRowCodec
{
    public const MAX_ROW_BYTES = 524288;

    /**
     * @param list<string|null> $values
     */
    public static function encode(array $values): string
    {
        if (!array_is_list($values) || $values === [] || count($values) > 1024) {
            throw new RuntimeException('backup_row_shape_invalid');
        }

        $encoded = [];
        $bytes = 0;

        foreach ($values as $value) {
            if ($value !== null && !is_string($value)) {
                throw new RuntimeException('backup_row_type_invalid');
            }

            $bytes += $value === null ? 0 : strlen($value);

            if ($bytes > self::MAX_ROW_BYTES) {
                throw new RuntimeException('backup_row_budget');
            }

            $encoded[] = $value === null ? null : base64_encode($value);
        }

        return json_encode([1, $encoded], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return list<string|null>
     */
    public static function decode(string $line, int $expectedColumns): array
    {
        if (strlen($line) > BackupChunkStore::MAX_CHUNK_BYTES || $expectedColumns < 1 || $expectedColumns > 1024) {
            throw new RuntimeException('backup_row_budget');
        }

        try {
            $frame = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('backup_row_encoding_invalid');
        }

        if (
            !is_array($frame)
            || !array_is_list($frame)
            || count($frame) !== 2
            || $frame[0] !== 1
            || !is_array($frame[1])
            || !array_is_list($frame[1])
            || count($frame[1]) !== $expectedColumns
        ) {
            throw new RuntimeException('backup_row_shape_invalid');
        }

        $values = [];

        foreach ($frame[1] as $value) {
            if ($value === null) {
                $values[] = null;
                continue;
            }

            $decoded = is_string($value) ? base64_decode($value, true) : false;

            if ($decoded === false || base64_encode($decoded) !== $value) {
                throw new RuntimeException('backup_row_encoding_invalid');
            }

            $values[] = $decoded;
        }

        if (self::encode($values) !== $line) {
            throw new RuntimeException('backup_row_encoding_invalid');
        }

        return $values;
    }
}
