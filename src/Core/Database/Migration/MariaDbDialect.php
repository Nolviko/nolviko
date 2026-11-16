<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

/**
 * MariaDB 10.11+ runs the MySQL 8.4 DDL of the core migrations with one change: MySQL's `utf8mb4_0900_ai_ci`
 * collation does not exist there, so text columns use `utf8mb4_unicode_520_ci` (Unicode 5.2.0, accent and
 * case insensitive). Identity columns (`*_normalized`, binary collations) are unaffected, so uniqueness and
 * lookups behave the same on both engines; only the sort order of free text can differ slightly.
 */
final class MariaDbDialect
{
    public const BACKEND = 'mariadb';

    public const COLLATION = 'utf8mb4_unicode_520_ci';

    private const MYSQL_COLLATION = 'utf8mb4_0900_ai_ci';

    /**
     * @param list<string> $mysql Statements written for MySQL 8.4.
     *
     * @return list<string>
     */
    public static function translate(array $mysql): array
    {
        return array_map(
            static fn(string $statement): string => str_replace(self::MYSQL_COLLATION, self::COLLATION, $statement),
            $mysql,
        );
    }
}
