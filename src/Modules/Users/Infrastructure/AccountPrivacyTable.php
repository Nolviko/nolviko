<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Durable grace-period requests; deleting an account removes its completed request through its FK. */
final class AccountPrivacyTable implements Migration
{
    public function id(): string
    {
        return '0031_account_privacy';
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
            'CREATE TABLE account_deletions (user_id BIGINT NOT NULL PRIMARY KEY, requested_by BIGINT NULL, '
                . 'requested_at DATETIME(6) NOT NULL, delete_after DATETIME(6) NOT NULL, '
                . 'INDEX ix_account_deletions_due (delete_after, user_id), '
                . 'CONSTRAINT fk_account_deletions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_account_deletions_actor FOREIGN KEY (requested_by) REFERENCES users (id) '
                . 'ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
