<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0004: `login_attempts` (DATABASE.md). `ip` holds LoginThrottle's network key (IPv4, or IPv6 /64), binary.
 */
final class LoginAttemptsTable implements Migration
{
    public const ID = '0004_login_attempts';

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
            'CREATE TABLE login_attempts ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NULL, '
                . 'username_normalized VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, '
                . 'ip VARBINARY(16) NOT NULL, '
                . 'succeeded TINYINT(1) NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_login_attempts_ip (ip, created_at), '
                . 'INDEX ix_login_attempts_account (username_normalized, created_at), '
                . 'CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
