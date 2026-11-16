<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Users\Domain\LoginAttempts;

/**
 * LoginAttempts on DBAL; both lookups use their `(key, created_at)` index.
 */
final class DbalLoginAttempts implements LoginAttempts
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function failuresForAccount(string $account, DateTimeImmutable $since): array
    {
        return $this->failures('username_normalized', $account, ParameterType::STRING, $since);
    }

    public function failuresForNetwork(string $network, DateTimeImmutable $since): array
    {
        return $this->failures('ip', $network, ParameterType::BINARY, $since);
    }

    public function record(
        string $account,
        string $network,
        bool $succeeded,
        ?int $userId,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('login_attempts', [
            'user_id' => $userId,
            'username_normalized' => $account === '' ? null : $account,
            'ip' => $network,
            'succeeded' => $succeeded ? 1 : 0,
            'created_at' => self::format($at),
        ], ['ip' => ParameterType::BINARY]);
    }

    public function clearAccount(string $account): void
    {
        $this->connection->delete('login_attempts', ['username_normalized' => $account, 'succeeded' => 0]);
    }

    public function clearNetwork(string $network): void
    {
        $this->connection->delete('login_attempts', ['ip' => $network], ['ip' => ParameterType::BINARY]);
    }

    public function prune(DateTimeImmutable $before): void
    {
        $this->connection->executeStatement(
            'DELETE FROM login_attempts WHERE created_at < ?',
            [self::format($before)],
        );
    }

    /**
     * @param string $column A fixed column name, never input.
     *
     * @return list<DateTimeImmutable>
     */
    private function failures(string $column, string $value, ParameterType $type, DateTimeImmutable $since): array
    {
        $times = $this->connection->fetchFirstColumn(
            'SELECT created_at FROM login_attempts WHERE ' . $column . ' = ? AND created_at >= ? AND succeeded = 0 '
                . 'ORDER BY created_at',
            [$value, self::format($since)],
            [$type, ParameterType::STRING],
        );
        $utc = new DateTimeZone('UTC');

        return array_map(
            static fn(mixed $time): DateTimeImmutable => new DateTimeImmutable((string) $time, $utc),
            $times,
        );
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
