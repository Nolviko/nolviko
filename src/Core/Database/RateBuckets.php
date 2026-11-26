<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;

/**
 * Interval limits on `rate_limit_buckets` (M03.15; shared by posting and reporting). Each bucket holds its last
 * accepted hit.
 *
 * - ensure() runs outside transactions and atomically creates/extends row retention without resetting hits. Creating
 *   it inside a transaction deadlocked under parallel requests: an UPDATE that misses a row takes a gap lock
 *   under REPEATABLE READ, and two such transactions block each other's INSERT.
 * - hit() is one conditional UPDATE on the existing row (only that row's exclusive lock). Inside a transaction a
 *   rolled-back request leaves no hit behind.
 */
final class RateBuckets
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    /** A new bucket accepts its first hit. */
    private const NEVER = '1970-01-01 00:00:00.000000';

    /** One in this many preparations also deletes expired buckets, outside posting transactions. */
    private const PRUNE_ONE_IN = 100;

    /** Buckets are pruned only this long after expiring, so a row ensured for a running request stays. */
    private const PRUNE_GRACE = '-1 hour';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function ensure(string $key, ?DateTimeImmutable $now = null): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('rate_bucket_prepare_in_transaction');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $retention = self::stamp($now->modify('+1 hour'));

        if (random_int(1, self::PRUNE_ONE_IN) === 1) {
            $this->prune($now);
        }

        // 2026-11-21 benchmark: an existing bucket that pruning cannot reach for at least half an hour needs no
        // write (each upsert is a committed write; searches and posts made two per request). Pruning deletes only
        // rows that expired more than PRUNE_GRACE ago, so this row outlives the request.
        $kept = $this->connection->fetchOne(
            'SELECT 1 FROM rate_limit_buckets WHERE bucket_key = ? AND expires_at >= ?',
            [$key, self::stamp($now->modify('-30 minutes'))],
        );

        if ($kept !== false) {
            return;
        }

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->connection->executeStatement(
                    SqlDialect::upsert(
                        $this->connection,
                        'INSERT INTO rate_limit_buckets (bucket_key, last_hit_at, expires_at) VALUES (?, ?, ?)',
                        'rate_limit_buckets',
                        ['bucket_key'],
                        'expires_at = GREATEST(@old.expires_at, ?)',
                    ),
                    [$key, self::NEVER, $retention, $retention],
                );

                return;
            } catch (RetryableException $failure) {
                // Retry only this autocommit statement, never part of a caller's rolled-back transaction.
                if ($this->connection->isTransactionActive() || $attempt === 2) {
                    throw $failure;
                }

                usleep(random_int(1000, 10000));
            }
        }
    }

    /** Accept an existing bucket in one autocommit statement. New or throttled buckets use the usual path. */
    public function hitStandalone(string $key, int $seconds, DateTimeImmutable $now): int
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('rate_bucket_prepare_in_transaction');
        }

        if ($this->tryHit($key, $seconds, $now)) {
            if (random_int(1, self::PRUNE_ONE_IN) === 1) {
                $this->prune($now);
            }

            return 0;
        }

        $this->ensure($key, $now);

        return $this->hit($key, $seconds, $now);
    }

    /**
     * @return int 0 when the hit was accepted (and recorded), otherwise the seconds still to wait.
     */
    public function hit(string $key, int $seconds, DateTimeImmutable $now): int
    {
        if ($this->tryHit($key, $seconds, $now)) {
            return 0;
        }

        // The UPDATE holds the row's lock, so this read sees the hit that made it refuse.
        $last = $this->connection->fetchOne('SELECT last_hit_at FROM rate_limit_buckets WHERE bucket_key = ?', [$key]);

        if ($last === false) {
            // Never silently bypass a rate limit if preparation and cleanup raced.
            throw new RuntimeException('rate_bucket_missing');
        }

        $lastHit = new DateTimeImmutable((string) $last, new DateTimeZone('UTC'));

        return max(1, $lastHit->getTimestamp() + $seconds - $now->getTimestamp());
    }

    private function tryHit(string $key, int $seconds, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE rate_limit_buckets SET last_hit_at = ?, expires_at = ? WHERE bucket_key = ? AND last_hit_at <= ?',
            [
                self::stamp($now),
                self::stamp($now->modify('+' . $seconds . ' seconds')),
                $key,
                self::stamp($now->modify('-' . $seconds . ' seconds')),
            ],
        ) === 1;
    }

    /**
     * Opportunistic cleanup must never acquire unrelated bucket locks inside a posting transaction.
     */
    public function prune(DateTimeImmutable $now): void
    {
        if ($this->connection->isTransactionActive()) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'DELETE FROM rate_limit_buckets WHERE expires_at < ?',
                [self::stamp($now->modify(self::PRUNE_GRACE))],
            );
        } catch (RetryableException) {
            // A competing request won; cleanup is disposable and can wait for another preparation.
        }
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
