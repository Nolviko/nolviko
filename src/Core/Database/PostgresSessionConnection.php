<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal PostgresSession: results of queries and prepared statements get normalized timestamps. */
final class PostgresSessionConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return new PostgresSessionStatement(parent::prepare($sql));
    }

    public function query(string $sql): Result
    {
        return PostgresSessionResult::of(parent::query($sql));
    }
}
