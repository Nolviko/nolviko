<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Second sign-in steps (2026-11-15, ADR-029): one row per member and provider with the provider's sealed state,
 * and the member's single-use recovery codes (SHA-256 only). Both go with the account.
 */
final class SecondFactorTables implements Migration
{
    public function id(): string
    {
        return '0052_second_factor_tables';
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
            'CREATE TABLE user_second_factors ('
                . 'user_id BIGINT NOT NULL, '
                . 'provider VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'state TEXT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'used_at DATETIME(6) NULL, '
                . 'PRIMARY KEY (user_id, provider), '
                . 'CONSTRAINT fk_second_factor_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            'CREATE TABLE user_recovery_codes ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'used_at DATETIME(6) NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_recovery_code UNIQUE (user_id, code_hash), '
                . 'CONSTRAINT fk_recovery_code_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
