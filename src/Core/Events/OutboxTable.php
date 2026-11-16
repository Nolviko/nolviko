<?php

declare(strict_types=1);

namespace Nolviko\Core\Events;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0013: `outbox_events`, the transactional outbox (ARCHITECTURE.md, EXTENSIONS.md AFTER events).
 *
 * A writer inserts its event in the same transaction as the change it describes, so an event exists exactly
 * when the change was committed. `dedupe_key` is UNIQUE, so recording the same event twice (a retried
 * request) keeps one row. Leasing, delivery and retries are M08.02/M08.03; the index serves that runner.
 */
final class OutboxTable implements Migration
{
    public const ID = '0013_outbox_events';

    public function id(): string
    {
        return self::ID;
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return [
            'CREATE TABLE outbox_events ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'aggregate_type VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'aggregate_id BIGINT NOT NULL, '
                . 'payload MEDIUMTEXT NOT NULL, '
                . 'dedupe_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'attempts INT NOT NULL DEFAULT 0, '
                . 'available_at DATETIME(6) NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_outbox_events_dedupe UNIQUE (dedupe_key), '
                . 'INDEX ix_outbox_events_queue (status, available_at, id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
