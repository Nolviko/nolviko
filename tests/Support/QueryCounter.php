<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * DBAL middleware that counts statements sent to the server (prepare, query, exec), for query budgets.
 */
final class QueryCounter implements Middleware
{
    public int $count = 0;

    /** @var list<string> SQL of every counted statement, for EXPLAIN in plan tests. */
    public array $statements = [];

    public function wrap(Driver $driver): Driver
    {
        $counter = $this;

        return new class ($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[SensitiveParameter]
                array $params,
            ): DriverConnection {
                $counter = $this->counter;

                return new class (parent::connect($params), $counter) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ++$this->counter->count;
                        $this->counter->statements[] = $sql;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        ++$this->counter->count;
                        $this->counter->statements[] = $sql;

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        ++$this->counter->count;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
