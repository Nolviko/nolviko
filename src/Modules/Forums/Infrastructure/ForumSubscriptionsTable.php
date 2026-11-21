<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Forum subscriptions (beta.18, roadmap 01): a member gets a notice for each new thread in a chosen forum. The
 * forum index serves the fanout (forum, member cursor); rows go with the member or the forum.
 */
final class ForumSubscriptionsTable implements Migration
{
    public const ID = '0076_forum_subscriptions';

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
            'CREATE TABLE forum_subscriptions ('
                . 'user_id BIGINT NOT NULL, '
                . 'forum_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, forum_id), '
                . 'INDEX ix_forum_subscriptions_forum (forum_id, user_id), '
                . 'CONSTRAINT fk_forum_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_forum_subscriptions_forum FOREIGN KEY (forum_id) REFERENCES forums (id) '
                . 'ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
