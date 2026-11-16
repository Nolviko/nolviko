<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Thread view counter (2026-11-08, ThreadViews). Existing threads start at 0. */
final class ThreadViewCounts implements Migration
{
    public function id(): string
    {
        return '0045_thread_view_counts';
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

        return ['ALTER TABLE threads ADD COLUMN view_count BIGINT UNSIGNED NOT NULL DEFAULT 0'];
    }
}
