<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Outside accounts linked to members (2026-11-15, sign in with Google): one member per provider account and one
 * account per provider and member. The provider's account ID (`subject`) is what identifies; the stored address
 * is only shown on the account page. Rows go with the member.
 */
final class ExternalIdentityTable implements Migration
{
    public function id(): string
    {
        return '0053_external_identities';
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
            'CREATE TABLE user_identities ('
                . 'provider VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'user_id BIGINT NOT NULL, '
                . 'email VARCHAR(254) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (provider, subject), '
                . 'CONSTRAINT uq_identity_user_provider UNIQUE (user_id, provider), '
                . 'CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
