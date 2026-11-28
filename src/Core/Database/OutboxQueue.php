<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Core\Events\OutboxLease;
use RuntimeException;

/** Claim committed events only, fence acknowledgements and retain exhausted work for owner recovery. */
final class OutboxQueue
{
    public const MAX_ATTEMPTS = 8;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Queues with no work for these handlers need no claiming transaction.
     *
     * @param list<string> $types
     */
    public function hasDue(array $types): bool
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('outbox_claim_transaction_active');
        }

        if ($types === []) {
            return false;
        }

        $clock = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "(statement_timestamp() AT TIME ZONE 'UTC')" : 'CURRENT_TIMESTAMP(6)';

        return (int) $this->connection->fetchOne(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM outbox_events WHERE status = 'pending' AND event_type IN (?) "
                . 'AND available_at <= ' . $clock . ") OR EXISTS (SELECT 1 FROM outbox_events WHERE status = 'running' "
                . 'AND event_type IN (?) AND lease_expires_at <= ' . $clock . ') THEN 1 ELSE 0 END',
            [$types, $types],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        ) === 1;
    }

    /**
     * @param list<string> $types Explicit registered handlers only; unknown work is left untouched.
     * @param (Closure(OutboxLease): void)|null $localWork Database-only work to commit with the claim.
     */
    public function claim(array $types, ?Closure $localWork = null): ?OutboxLease
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('outbox_claim_transaction_active');
        }

        if ($types === []) {
            return null;
        }

        return $this->connection->transactional(function (Connection $db) use ($types, $localWork): ?OutboxLease {
            $now = $this->now();
            $stamp = $now->format('Y-m-d H:i:s.u');
            $slots = implode(', ', array_fill(0, count($types), '?'));
            $select = 'SELECT id, event_type, aggregate_id, attempts, revision, '
                . 'CASE WHEN OCTET_LENGTH(payload) <= 16384 THEN payload ELSE NULL END AS payload '
                . 'FROM outbox_events WHERE event_type IN (' . $slots . ') AND ';
            // 2026-11-21 benchmark: the former single "pending OR expired running" condition made MySQL scan and sort
            // the whole table (completed events included) on every claim. Expired leases come first (recovery, few
            // rows, ix_outbox_events_lease); pending work then walks ix_outbox_events_queue in order and stops at
            // the first registered type.
            $row = $db->fetchAssociative(
                $select . "status = 'running' AND lease_expires_at <= ? "
                    . 'ORDER BY lease_expires_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                [...$types, $stamp],
            );

            if ($row === false) {
                $row = $db->fetchAssociative(
                    $select . "status = 'pending' AND available_at <= ? "
                        . 'ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                    [...$types, $stamp],
                );
            }

            if ($row === false) {
                return null;
            }

            if ((int) $row['attempts'] >= self::MAX_ATTEMPTS || !is_string($row['payload'])) {
                $db->executeStatement(
                    "UPDATE outbox_events SET status = 'failed', owner_token = NULL, lease_expires_at = NULL, "
                        . "last_error = 'outbox_attempts_or_payload_invalid', revision = revision + 1 WHERE id = ?",
                    [$row['id']],
                );

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $revision = (int) $row['revision'] + 1;
            $db->executeStatement(
                "UPDATE outbox_events SET status = 'running', owner_token = ?, lease_expires_at = ?, "
                    . 'revision = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?',
                [$token, $now->modify('+120 seconds')->format('Y-m-d H:i:s.u'), $revision, $row['id']],
            );

            $lease = new OutboxLease(
                (int) $row['id'],
                $row['event_type'],
                (int) $row['aggregate_id'],
                $row['payload'],
                $token,
                $revision,
                (int) $row['attempts'] + 1
            );
            if ($localWork !== null) {
                $localWork($lease);
            }

            return $lease;
        });
    }

    /** Held inside the handler transaction, so another worker cannot mutate the row before this transaction ends. */
    public function guard(OutboxLease $lease): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new RuntimeException('outbox_guard_transaction_required');
        }

        $found = $this->connection->fetchOne(
            'SELECT 1 FROM outbox_events WHERE id = ? AND owner_token = ? AND revision = ? '
                . "AND status = 'running' AND lease_expires_at > ? FOR UPDATE",
            [$lease->id, $lease->token, $lease->revision, $this->now()->format('Y-m-d H:i:s.u')],
        );

        if ($found === false) {
            throw new RuntimeException('outbox_lease_lost');
        }
    }

    public function complete(OutboxLease $lease): void
    {
        $this->guard($lease);
        $this->connection->executeStatement(
            "UPDATE outbox_events SET status = 'completed', owner_token = NULL, lease_expires_at = NULL, "
                . 'completed_at = ?, revision = revision + 1 WHERE id = ? AND revision = ?',
            [$this->now()->format('Y-m-d H:i:s.u'), $lease->id, $lease->revision],
        );
    }

    /** Successful bounded progress is not a failure; retain the same event and reset its consecutive-failure count. */
    public function defer(OutboxLease $lease): void
    {
        $this->guard($lease);
        $this->connection->executeStatement(
            "UPDATE outbox_events SET status = 'pending', available_at = ?, owner_token = NULL, "
                . 'lease_expires_at = NULL, attempts = 0, revision = revision + 1 WHERE id = ? AND revision = ?',
            [$this->now()->modify('+1 second')->format('Y-m-d H:i:s.u'), $lease->id, $lease->revision],
        );
    }

    public function retry(OutboxLease $lease): void
    {
        $next = $this->now()->modify('+' . min(3600, 5 * (2 ** $lease->attempt)) . ' seconds');
        $this->connection->executeStatement(
            'UPDATE outbox_events SET status = ?, available_at = ?, owner_token = NULL, lease_expires_at = NULL, '
                . "last_error = 'outbox_handler_failed', revision = revision + 1 "
                . "WHERE id = ? AND owner_token = ? AND revision = ? AND status = 'running'",
            [$lease->attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending', $next->format('Y-m-d H:i:s.u'),
                $lease->id, $lease->token, $lease->revision],
        );
    }

    /**
     * Deletes up to $limit events completed more than $days ago (beta.17: completed work was kept for ever, so the
     * table grew with every post). Failed and pending events stay for the owner. Returns the number deleted.
     */
    public function prune(int $days = 7, int $limit = 500): int
    {
        if ($this->connection->isTransactionActive() || $days < 1 || $limit < 1 || $limit > 5000) {
            return 0;
        }
        $cutoff = $this->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s.u');
        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM outbox_events WHERE status = 'completed' AND available_at < ? AND completed_at < ? "
                . 'ORDER BY available_at, id LIMIT ' . $limit,
            [$cutoff, $cutoff],
        );
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            "DELETE FROM outbox_events WHERE status = 'completed' AND id IN (?)",
            [array_map(intval(...), $ids)],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * Marks events no handler takes as done once they are $hours old (2026-11-27, found on the community forum: the
     * core records `post.edited`, `thread.locked` and similar events for extensions; with no extension subscribed
     * they waited for ever, filled the table and showed as "waiting too long" in Admin > Background tasks). They
     * are then deleted by prune() like other finished work. An extension enabled later sees new events only.
     *
     * @param list<string> $handled Event types the runner has a handler for.
     */
    public function retireUnhandled(array $handled, int $hours = 24, int $limit = 500): int
    {
        if ($this->connection->isTransactionActive() || $handled === [] || $hours < 1 || $limit < 1 || $limit > 5000) {
            return 0;
        }
        $now = $this->now();
        $cutoff = $now->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s.u');
        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM outbox_events WHERE status = 'pending' AND available_at < ? AND event_type NOT IN (?) "
                . 'ORDER BY available_at, id LIMIT ' . $limit,
            [$cutoff, array_values($handled)],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            "UPDATE outbox_events SET status = 'completed', completed_at = ?, owner_token = NULL "
                . "WHERE status = 'pending' AND id IN (?)",
            [$now->format('Y-m-d H:i:s.u'), array_map(intval(...), $ids)],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        );
    }

    private function now(): DateTimeImmutable
    {
        $sql = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "SELECT clock_timestamp() AT TIME ZONE 'UTC'" : 'SELECT CURRENT_TIMESTAMP(6)';

        return new DateTimeImmutable((string) $this->connection->fetchOne($sql), new DateTimeZone('UTC'));
    }
}
