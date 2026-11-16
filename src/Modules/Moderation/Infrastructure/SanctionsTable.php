<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0021: `user_bans` and `warnings` (M04.09). A ban is active while it is not lifted and its `expires_at` is
 * NULL or in the future; a warning counts while its `expires_at` is NULL or in the future. Expiry is a condition
 * of the query that reads them, so nothing has to run on a schedule.
 */
final class SanctionsTable implements Migration
{
    public const ID = '0021_sanctions';

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

        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $byUser = ' FOREIGN KEY (%s) REFERENCES users (id) ON DELETE SET NULL';

        return [
            'CREATE TABLE user_bans ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'reason VARCHAR(500) NOT NULL, '
                . 'created_by BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'expires_at DATETIME(6) NULL, '
                . 'lifted_at DATETIME(6) NULL, '
                . 'lifted_by BIGINT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_user_bans_user (user_id, lifted_at, expires_at), '
                . 'CONSTRAINT fk_user_bans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_user_bans_created_by' . sprintf($byUser, 'created_by') . ', '
                . 'CONSTRAINT fk_user_bans_lifted_by' . sprintf($byUser, 'lifted_by')
                . ')' . $options,
            'CREATE TABLE warnings ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'points INT NOT NULL, '
                . 'reason VARCHAR(500) NOT NULL, '
                . 'created_by BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'expires_at DATETIME(6) NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_warnings_user (user_id, expires_at), '
                . 'CONSTRAINT fk_warnings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_warnings_created_by' . sprintf($byUser, 'created_by')
                . ')' . $options,
        ];
    }
}
