<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0024: `password_reset_tokens` (DATABASE.md, M02.05) and `users.session_epoch`. Only the SHA-256 of a token is
 * stored, with the SHA-256 of the requesting network (never the address); tokens last an hour and are used
 * once. The session epoch is raised when the password is reset, which ends every session started before.
 */
final class PasswordResetTable implements Migration
{
    public const ID = '0024_password_reset_tokens';

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
            'CREATE TABLE password_reset_tokens ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'requested_network CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'used_at DATETIME(6) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_password_reset_token UNIQUE (token_hash), '
                . 'INDEX ix_password_reset_user (user_id, created_at), '
                . 'CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            'ALTER TABLE users ADD COLUMN session_epoch INT NOT NULL DEFAULT 0',
        ];
    }
}
