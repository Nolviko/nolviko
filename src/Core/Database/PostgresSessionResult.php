<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Result;

/** @internal PostgresSession: timestamp columns in MySQL text form. */
final class PostgresSessionResult extends AbstractResultMiddleware
{
    /** @param array<int, string> $columns Index => name of the timestamp columns. */
    private function __construct(Result $result, private readonly array $columns)
    {
        parent::__construct($result);
    }

    public static function of(Result $result): Result
    {
        $columns = PostgresSession::timestampColumns($result);

        return $columns === [] ? $result : new self($result, $columns);
    }

    public function fetchNumeric(): array|false
    {
        $row = parent::fetchNumeric();

        return $row === false ? false : $this->numeric($row);
    }

    public function fetchAssociative(): array|false
    {
        $row = parent::fetchAssociative();

        return $row === false ? false : $this->associative($row);
    }

    public function fetchOne(): mixed
    {
        $value = parent::fetchOne();

        return isset($this->columns[0]) ? PostgresSession::timestamp($value) : $value;
    }

    public function fetchAllNumeric(): array
    {
        return array_map($this->numeric(...), parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return array_map($this->associative(...), parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        $values = parent::fetchFirstColumn();

        return isset($this->columns[0]) ? array_map(PostgresSession::timestamp(...), $values) : $values;
    }

    /**
     * @param list<mixed> $row
     *
     * @return list<mixed>
     */
    private function numeric(array $row): array
    {
        foreach ($this->columns as $index => $_) {
            $row[$index] = PostgresSession::timestamp($row[$index] ?? null);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function associative(array $row): array
    {
        foreach ($this->columns as $name) {
            if (array_key_exists($name, $row)) {
                $row[$name] = PostgresSession::timestamp($row[$name]);
            }
        }

        return $row;
    }
}
