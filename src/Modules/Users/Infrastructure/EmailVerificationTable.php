<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0023: `email_verification_tokens` (DATABASE.md, M02.03). Only the SHA-256 of the token is stored; a token is
 * for one user and one email address, expires after 24 hours and is used once.
 */
final class EmailVerificationTable implements Migration
{
    public const ID = '0023_email_verification_tokens';

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
            'CREATE TABLE email_verification_tokens ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'email_normalized VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . 'token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'used_at DATETIME(6) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_email_verification_token UNIQUE (token_hash), '
                . 'INDEX ix_email_verification_user (user_id, created_at), '
                . 'CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
