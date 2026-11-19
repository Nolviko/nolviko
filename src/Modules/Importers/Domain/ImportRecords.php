<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

use RuntimeException;

/**
 * The shape every record handed to the import target must have (ADR-038, contract 1, 2026-11-20). Sources, also the
 * importer extension, are trusted code but not trusted data: a record is a flat map of scalars with a numeric ID,
 * an ordered cursor key and bounded text; `groups` alone is a short list of IDs. Anything else stops the batch
 * before a single row is written.
 */
final class ImportRecords
{
    /** Decoded text may grow up to three times from a single-byte source charset (131072 bytes at the source). */
    public const TEXT_BYTES = 393216;

    public const FIELDS = 48;

    /** Longest converted text (post, signature, private message) the forum stores, in characters. */
    public const BODY_CHARACTERS = 50000;

    /** Converted text from a record: valid UTF-8, no NUL byte, within the forum's limit. */
    public static function body(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new RuntimeException('import_text_invalid');
        }
        if (mb_strlen($value, 'UTF-8') > self::BODY_CHARACTERS) {
            throw new RuntimeException('import_body_oversize');
        }
        return $value;
    }

    public static function assert(array $row, string $after): void
    {
        $key = $row['_key'] ?? null;
        if (
            !is_int($row['id'] ?? null) || $row['id'] < 0 || !is_string($key)
            || preg_match('/^[0-9]{19}(?::[0-9]{19}){0,2}$/D', $key) !== 1 || strcmp($key, $after) <= 0
            || count($row) > self::FIELDS
        ) {
            throw new RuntimeException('import_source_order_invalid');
        }
        // The source's own fingerprint of the raw row: how the target notices a source that changed under it.
        if (!is_string($row['_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $row['_hash']) !== 1) {
            throw new RuntimeException('import_record_invalid');
        }
        foreach ($row as $field => $value) {
            if (!is_string($field) || preg_match('/^_?[a-z][a-z0-9_]{0,31}$/D', $field) !== 1) {
                throw new RuntimeException('import_record_invalid');
            }
            if ($field === 'groups') {
                if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
                    throw new RuntimeException('import_record_invalid');
                }
                foreach ($value as $group) {
                    if (!is_int($group) || $group < 1) {
                        throw new RuntimeException('import_record_invalid');
                    }
                }
                continue;
            }
            if (is_string($value) ? strlen($value) > self::TEXT_BYTES : !is_scalar($value) && $value !== null) {
                throw new RuntimeException('import_record_invalid');
            }
        }
    }
}
