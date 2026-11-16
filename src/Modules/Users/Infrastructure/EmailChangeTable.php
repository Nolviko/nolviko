<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0025: `email_change_tokens` (M02.16). A requested address is kept here until its owner confirms it from that
 * mailbox; only the SHA-256 of the token is stored, the link lasts 24 hours and is used once.
 */
final class EmailChangeTable implements Migration
{
    public const ID = '0025_email_change_tokens';

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
            'CREATE TABLE email_change_tokens ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'new_email VARCHAR(254) NOT NULL, '
                . 'token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'used_at DATETIME(6) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_email_change_token UNIQUE (token_hash), '
                . 'INDEX ix_email_change_user (user_id, created_at), '
                . 'CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
