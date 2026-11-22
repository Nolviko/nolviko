<?php

declare(strict_types=1);

namespace Nolviko\Modules\Calendar\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0082 (beta.18): calendar events. One event per thread (a date or a date range, no recurrence); visibility is the
 * thread's, read through its forum at query time, so moving a thread needs no update here. The range index serves
 * the month and list views.
 */
final class CalendarTables implements Migration
{
    public function id(): string
    {
        return '0082_thread_events';
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
            'CREATE TABLE thread_events (thread_id BIGINT NOT NULL PRIMARY KEY, user_id BIGINT NULL, starts_on DATE '
                . 'NOT NULL, ends_on DATE NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT '
                . 'NULL, INDEX ix_thread_events_range (starts_on, ends_on), CONSTRAINT fk_thread_events_thread '
                . 'FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE, CONSTRAINT '
                . 'fk_thread_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
