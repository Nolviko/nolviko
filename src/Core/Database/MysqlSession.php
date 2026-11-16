<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * DBAL middleware for the MySQL family: every new physical connection gets the same session settings in one
 * round trip, whatever the shared host's server defaults are (utf8mb4, UTC, strict SQL mode).
 */
final class MysqlSession implements Middleware
{
    /** MySQL 8.4's default mode plus STRICT_ALL_TABLES, so non-transactional tables are strict too. */
    public const SQL_MODES = ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'NO_ZERO_IN_DATE',
        'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'];

    public const CHARSET = 'utf8mb4';

    public const TIME_ZONE = '+00:00';

    public static function initStatement(): string
    {
        return "SET NAMES '" . self::CHARSET . "', time_zone = '" . self::TIME_ZONE . "', sql_mode = '"
            . implode(',', self::SQL_MODES) . "'";
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): DriverConnection {
                $connection = parent::connect($params);
                $connection->exec(MysqlSession::initStatement());

                return $connection;
            }
        };
    }
}
