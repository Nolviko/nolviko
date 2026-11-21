<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Guest posting (ADR-040): a guest post has no account (`user_id` NULL) and carries the display name the guest
 * typed. Threads keep the starter name too, for lists.
 */
final class GuestPostColumns implements Migration
{
    public const ID = '0077_guest_posts';

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
            'ALTER TABLE posts ADD COLUMN guest_name VARCHAR(50) NULL',
            'ALTER TABLE threads ADD COLUMN guest_name VARCHAR(50) NULL',
        ];
    }
}
