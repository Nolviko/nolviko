<?php

declare(strict_types=1);

namespace Nolviko\Modules\Tasks\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Additive queue/filter indexes; no destructive migration or cache-essential data. */
final class TaskIndexes implements Migration
{
    public function id(): string
    {
        return '0041_task_search_indexes';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if (!in_array($backend, ['mysql', 'postgresql'], true)) {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return [
            'CREATE INDEX ix_outbox_events_type_queue ON outbox_events (event_type, status, available_at, id)',
            'CREATE INDEX ix_posts_search_date ON posts (created_at, status, id)',
        ];
    }
}
