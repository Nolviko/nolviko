<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0002: `users` (DATABASE.md). The normalized columns are binary-collated with named UNIQUE constraints, so
 * equality is exactly the application's normalization and DbalUserRepository can tell which one was violated.
 * IDs come from AUTO_INCREMENT and are therefore positive (MySQL forbids CHECK constraints on that column).
 */
final class UsersTable implements Migration
{
    public const ID = '0002_users';

    public const USERNAME_CONSTRAINT = 'uq_users_username_normalized';

    public const EMAIL_CONSTRAINT = 'uq_users_email_normalized';

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
            'CREATE TABLE users ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'username VARCHAR(64) NOT NULL, '
                . 'username_normalized VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . 'email VARCHAR(254) NOT NULL, '
                . 'email_normalized VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . 'password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'preferred_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT ' . self::USERNAME_CONSTRAINT . ' UNIQUE (username_normalized), '
                . 'CONSTRAINT ' . self::EMAIL_CONSTRAINT . ' UNIQUE (email_normalized)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
