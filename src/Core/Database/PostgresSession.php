<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\Result as PdoResult;
use Doctrine\DBAL\Driver\Result;
use PDOStatement;
use SensitiveParameter;

/**
 * DBAL middleware for PostgreSQL (2026-11-14), the counterpart of MysqlSession:
 *
 * - every new physical connection gets UTF-8 and UTC in one round trip, whatever the server or role defaults;
 * - timestamps read back in MySQL's text form. PostgreSQL prints `timestamp(6)` without trailing zeros
 *   (`10:00:00`, `10:00:00.5`) where MySQL's DATETIME(6) always has six digits, and repositories compare and
 *   pass on these strings (cursors, read marks, leases). Columns PDO reports as `timestamp` with microsecond
 *   precision are padded to six digits; `timestamptz` values (CURRENT_TIMESTAMP) also lose their `+00`. The
 *   column type decides, so text that merely looks like a date is never touched.
 *
 * It must be the innermost middleware (ConnectionFactory puts it first): it reads the column types from the
 * PDO statement directly underneath.
 */
final class PostgresSession implements Middleware
{
    public const TIME_ZONE = 'UTC';

    /** PostgreSQL's text form of a timestamp: optional 1 to 6 fraction digits, `+00` for timestamptz in UTC. */
    private const TIMESTAMP = '/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\.(\d{1,6}))?(?:\+00(?::00)?)?$/';

    public static function initStatement(): string
    {
        return "SET client_encoding = 'UTF8'; SET TIME ZONE '" . self::TIME_ZONE . "'";
    }

    /**
     * Column index => column name for the timestamp columns of a PDO result to normalize.
     *
     * @return array<int, string>
     */
    public static function timestampColumns(Result $result): array
    {
        if (!$result instanceof PdoResult) {
            return [];
        }

        /** @var PDOStatement $statement */
        $read = Closure::bind(
            static fn(PdoResult $inner): PDOStatement => $inner->statement,
            null,
            PdoResult::class,
        );
        $statement = $read($result);
        $columns = [];

        for ($index = 0, $count = $statement->columnCount(); $index < $count; ++$index) {
            $meta = $statement->getColumnMeta($index);
            $type = is_array($meta) ? ($meta['native_type'] ?? '') : '';
            $precision = is_array($meta) ? (int) ($meta['precision'] ?? -1) : -1;

            if (($type === 'timestamp' || $type === 'timestamptz') && $precision !== 0) {
                $columns[$index] = (string) ($meta['name'] ?? '');
            }
        }

        return $columns;
    }

    /** `2026-01-01 10:00:00[.5][+00]` => `2026-01-01 10:00:00.500000`. */
    public static function timestamp(mixed $value): mixed
    {
        if (!is_string($value) || preg_match(self::TIMESTAMP, $value, $m) !== 1) {
            return $value;
        }

        return $m[1] . '.' . str_pad($m[2] ?? '', 6, '0');
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): DriverConnection {
                $connection = parent::connect($params);
                $connection->exec(PostgresSession::initStatement());

                return new PostgresSessionConnection($connection);
            }
        };
    }
}
