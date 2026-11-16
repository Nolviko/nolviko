<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use Nolviko\Modules\Permissions\Domain\ForumModeratorCapabilities;

/**
 * Moderator capability rows as a derived table. MySQL/MariaDB: bound role keys/values use the same ASCII binary
 * collation as stored rules; PostgreSQL (2026-11-14): plain casts to the stored column types.
 */
final class MysqlModeratorProjection
{
    public const ALLOW = "CONVERT('1' USING ascii) COLLATE ascii_bin";

    public static function allow(bool $postgres): string
    {
        return $postgres ? "CAST('1' AS VARCHAR(32))" : self::ALLOW;
    }

    public static function rows(bool $postgres = false): string
    {
        return implode(' UNION ALL ', array_fill(
            0,
            count(ForumModeratorCapabilities::KEYS),
            $postgres ? 'SELECT CAST(? AS VARCHAR(100)) AS permission_key'
                : 'SELECT CONVERT(? USING ascii) COLLATE ascii_bin AS permission_key'
        ));
    }
}
