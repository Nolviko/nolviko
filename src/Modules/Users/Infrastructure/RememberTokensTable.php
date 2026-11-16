<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0003: `remember_tokens` (DATABASE.md): selector looked up by index, only the SHA-256 of the validator stored;
 * deleted with their user.
 */
final class RememberTokensTable implements Migration
{
    public const ID = '0003_remember_tokens';

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
            'CREATE TABLE remember_tokens ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'selector CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'last_used_at DATETIME(6) NULL, '
                . 'user_agent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_remember_tokens_selector UNIQUE (selector), '
                . 'INDEX ix_remember_tokens_user (user_id), '
                . 'CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
