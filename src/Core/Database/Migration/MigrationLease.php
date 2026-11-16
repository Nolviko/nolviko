<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * A row in `migration_leases` that lets only one request migrate at a time (two admins, a reload, a retry).
 *
 * Taken by INSERT on the primary key, so the database decides the race. An expired lease (a request that
 * died) is removed first; the holder renews before every step and loses the lease if someone took it over.
 */
final class MigrationLease
{
    public const NAME = 'schema';

    public const DEFAULT_SECONDS = 120;

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    private ?string $owner = null;

    /**
     * @param Closure(): DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $clock,
        private readonly int $seconds = self::DEFAULT_SECONDS,
    ) {
    }

    /**
     * @throws MigrationException `migration_locked` while another request holds an unexpired lease.
     */
    public function acquire(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM migration_leases WHERE lease_name = ? AND expires_at < ?',
            [self::NAME, $this->time(0)],
        );
        $owner = bin2hex(random_bytes(16));

        try {
            $this->connection->insert('migration_leases', [
                'lease_name' => self::NAME,
                'owner_token' => $owner,
                'expires_at' => $this->time($this->seconds),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new MigrationException('migration_locked');
        }

        $this->owner = $owner;
    }

    /**
     * @throws MigrationException `migration_lease_lost` when the lease expired and another request took it.
     */
    public function renew(): void
    {
        $renewed = $this->connection->executeStatement(
            'UPDATE migration_leases SET expires_at = ? WHERE lease_name = ? AND owner_token = ?',
            [$this->time($this->seconds), self::NAME, (string) $this->owner],
        );

        // MySQL reports 0 changed rows when the value is identical (same microsecond); only a missing row is loss.
        if ($renewed === 0 && !$this->held()) {
            $this->owner = null;

            throw new MigrationException('migration_lease_lost');
        }
    }

    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM migration_leases WHERE lease_name = ? AND owner_token = ?',
            [self::NAME, $this->owner],
        );
        $this->owner = null;
    }

    private function held(): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM migration_leases WHERE lease_name = ? AND owner_token = ?',
            [self::NAME, (string) $this->owner],
        ) !== false;
    }

    private function time(int $offsetSeconds): string
    {
        return ($this->clock)()
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify(sprintf('%+d seconds', $offsetSeconds))
            ->format(self::TIME_FORMAT);
    }
}
