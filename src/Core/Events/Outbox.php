<?php

declare(strict_types=1);

namespace Nolviko\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Throwable;

/**
 * Records AFTER events in `outbox_events` inside the caller's transaction (M08.01, minimal part). Consumers
 * are at-least-once and must be idempotent; the dedupe key keeps one row per logical event.
 */
final class Outbox
{
    public const PENDING = 'pending';

    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string $eventType Namespaced, past tense: `thread.created`.
     * @param array<string, scalar|null|array<mixed>> $payload Small, JSON-serializable facts (IDs, not content).
     * @param string|null $dedupeKey Null means `<type>:<aggregate id>` (one such event per aggregate).
     *
     * @return bool False when an event with the same dedupe key already exists.
     *
     * @throws LogicException `outbox_requires_transaction` or `outbox_event_type_invalid`.
     */
    public function record(
        string $eventType,
        string $aggregateType,
        int $aggregateId,
        array $payload,
        ?string $dedupeKey = null,
        ?DateTimeImmutable $availableAt = null,
    ): bool {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('outbox_requires_transaction');
        }

        if (preg_match(self::TYPE_PATTERN, $eventType) !== 1) {
            throw new LogicException('outbox_event_type_invalid');
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($body) > 16384) {
            throw new LogicException('outbox_payload_too_large');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        // A nested transaction is a savepoint (DBAL 4), so a duplicate key leaves the caller's transaction
        // usable on PostgreSQL too, where a failed statement otherwise aborts the whole transaction.
        $this->connection->beginTransaction();

        try {
            $this->connection->insert('outbox_events', [
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $body,
                'dedupe_key' => $dedupeKey ?? $eventType . ':' . $aggregateId,
                'status' => self::PENDING,
                'available_at' => $availableAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') ?? $now,
                'created_at' => $now,
            ]);
        } catch (Throwable $exception) {
            // Always close the savepoint, so the caller's own rollback reaches the real transaction.
            $this->connection->rollBack();

            if ($exception instanceof UniqueConstraintViolationException) {
                return false;
            }

            throw $exception;
        }

        $this->connection->commit();

        return true;
    }
}
