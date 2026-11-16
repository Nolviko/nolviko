<?php

declare(strict_types=1);

namespace Nolviko\Core\Events;

/** Portable standalone durable-queue schema; full PostgreSQL application migration acceptance is separate. */
final class OutboxSchema
{
    public static function postgres(): array
    {
        return [
            'CREATE TABLE outbox_events (id BIGSERIAL PRIMARY KEY, event_type VARCHAR(100) NOT NULL, '
                . 'aggregate_type VARCHAR(60) NOT NULL, aggregate_id BIGINT NOT NULL, payload TEXT NOT NULL, '
                . 'dedupe_key VARCHAR(191) UNIQUE, status VARCHAR(16) NOT NULL DEFAULT \'pending\', '
                . 'attempts INT NOT NULL DEFAULT 0, available_at TIMESTAMP(6) NOT NULL, '
                . 'created_at TIMESTAMP(6) NOT NULL, owner_token CHAR(64), lease_expires_at TIMESTAMP(6), '
                . 'revision BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(80), completed_at TIMESTAMP(6))',
            'CREATE INDEX ix_outbox_events_queue ON outbox_events (status, available_at, id)',
            'CREATE INDEX ix_outbox_events_lease ON outbox_events (status, lease_expires_at, id)',
        ];
    }
}
