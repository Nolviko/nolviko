<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;

/** @internal PostgresSession: executed statements get normalized timestamps. */
final class PostgresSessionStatement extends AbstractStatementMiddleware
{
    public function execute(): Result
    {
        return PostgresSessionResult::of(parent::execute());
    }
}
