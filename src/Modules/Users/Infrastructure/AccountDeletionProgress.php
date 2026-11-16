<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Durable deletion progress; legacy requests remain unstarted until explicitly finalized or newly consented. */
final class AccountDeletionProgress implements Migration
{
    public function id(): string
    {
        return '0035_account_deletion_progress';
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
            'ALTER TABLE account_deletions ADD processing_started_at DATETIME(6) NULL',
            'ALTER TABLE account_deletions ADD cleanup_plan MEDIUMTEXT NULL',
            'ALTER TABLE account_deletions ADD protect_admin TINYINT NOT NULL DEFAULT 0',
        ];
    }
}
