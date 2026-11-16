<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Fenced durable delivery metadata; old deletion requests retain manual-only consent. */
final class OutboxLeases implements Migration
{
    public function id(): string
    {
        return '0032_outbox_leases';
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
            'ALTER TABLE outbox_events ADD owner_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'ALTER TABLE outbox_events ADD lease_expires_at DATETIME(6) NULL',
            'ALTER TABLE outbox_events ADD revision BIGINT NOT NULL DEFAULT 0',
            'ALTER TABLE outbox_events ADD last_error VARCHAR(80) NULL',
            'ALTER TABLE outbox_events ADD completed_at DATETIME(6) NULL',
            'CREATE INDEX ix_outbox_events_lease ON outbox_events (status, lease_expires_at, id)',
            'ALTER TABLE account_deletions ADD auto_finalize TINYINT NOT NULL DEFAULT 0',
        ];
    }
}
