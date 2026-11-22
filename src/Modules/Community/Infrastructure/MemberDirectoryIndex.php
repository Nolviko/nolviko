<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0068 (beta.18, roadmap 24): member directory orders. Join date reads `(status, created_at, id)` in order; the
 * username order already has the unique `username_normalized` index.
 */
final class MemberDirectoryIndex implements Migration
{
    public const ID = '0068_member_directory_index';

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

        return ['CREATE INDEX ix_users_directory_joined ON users (status, created_at, id)'];
    }
}
