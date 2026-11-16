<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0020: indexes for the moderator queue (M04.07). Pending posts and accounts waiting for approval are read
 * newest first by status; without these the queue would scan every post or user.
 */
final class QueueIndexes implements Migration
{
    public const ID = '0020_queue_indexes';

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
            'ALTER TABLE posts ADD INDEX ix_posts_status (status, id)',
            'ALTER TABLE users ADD INDEX ix_users_status (status, id)',
        ];
    }
}
