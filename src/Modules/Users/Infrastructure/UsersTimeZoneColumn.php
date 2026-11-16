<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0007: `users.time_zone`, the IANA zone a user reads dates in (NULL: the site default). Expand only: a
 * nullable column needs no backfill.
 */
final class UsersTimeZoneColumn implements Migration
{
    public const ID = '0007_users_time_zone';

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
            'ALTER TABLE users ADD COLUMN time_zone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL '
                . 'AFTER preferred_locale',
        ];
    }
}
