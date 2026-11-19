<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Source dates as the forum stores them. Converting a source forum's text (charsets, BBCode, phpBB XML) belongs to
 * the source side since ADR-038 (the importer extension's SourceText).
 */
final class ImportText
{
    public static function timestamp(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[0-9]{1,10}$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('import_timestamp_invalid');
        }
        return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
