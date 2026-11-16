<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0022: `bans` for IP, IP range, email and email-domain entries (M04.09a; user bans live in `user_bans`).
 * Addresses are `inet_pton` bytes, so `ix_bans_ip` serves `ip_start <= ? AND ip_end >= ?` for one address
 * length; `ix_bans_email` serves exact email and domain lookups. Active means not lifted and not expired.
 */
final class BansTable implements Migration
{
    public const ID = '0022_bans';

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

        $byUser = ' FOREIGN KEY (%s) REFERENCES users (id) ON DELETE SET NULL';

        return [
            'CREATE TABLE bans ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'ban_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'ip_start VARBINARY(16) NULL, '
                . 'ip_end VARBINARY(16) NULL, '
                . 'email_pattern VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, '
                . 'reason VARCHAR(500) NOT NULL, '
                . 'created_by BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'expires_at DATETIME(6) NULL, '
                . 'lifted_at DATETIME(6) NULL, '
                . 'lifted_by BIGINT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_bans_ip (ip_start, ip_end), '
                . 'INDEX ix_bans_email (email_pattern), '
                . 'CONSTRAINT fk_bans_created_by' . sprintf($byUser, 'created_by') . ', '
                . 'CONSTRAINT fk_bans_lifted_by' . sprintf($byUser, 'lifted_by')
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
