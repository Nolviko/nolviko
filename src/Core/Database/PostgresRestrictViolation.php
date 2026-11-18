<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Query;

/**
 * PostgreSQL 18 reports an `ON DELETE RESTRICT` violation as SQLSTATE 23001 (restrict_violation) where earlier
 * releases used 23503 (beta.10 CI matrix). Doctrine DBAL maps only 23503 to ForeignKeyConstraintViolationException,
 * so "a forum with subforums or threads cannot be deleted" turned into a database error. This maps both to the same
 * exception, so every caller keeps one catch.
 */
final class PostgresRestrictViolation implements Middleware
{
    public const RESTRICT_VIOLATION = '23001';

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function getExceptionConverter(): ExceptionConverter
            {
                return new class (parent::getExceptionConverter()) implements ExceptionConverter {
                    public function __construct(private readonly ExceptionConverter $inner)
                    {
                    }

                    public function convert(Exception $exception, ?Query $query): DriverException
                    {
                        return $exception->getSQLState() === PostgresRestrictViolation::RESTRICT_VIOLATION
                            ? new ForeignKeyConstraintViolationException($exception, $query)
                            : $this->inner->convert($exception, $query);
                    }
                };
            }
        };
    }
}
