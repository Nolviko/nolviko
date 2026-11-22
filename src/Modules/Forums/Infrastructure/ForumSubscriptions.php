<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Throwable;

/**
 * Forum subscriptions (beta.18 roadmap 01): a member hears about new threads of a forum. Notices are sent by
 * CommunityNotifications::fanout and checked for readability when the inbox is read.
 */
final class ForumSubscriptions
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function has(int $userId, int $forumId): bool
    {
        try {
            return $this->db->fetchOne(
                'SELECT 1 FROM forum_subscriptions WHERE user_id = ? AND forum_id = ?',
                [$userId, $forumId],
            ) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function set(int $userId, int $forumId, bool $enable): void
    {
        if (!$enable) {
            $this->db->delete('forum_subscriptions', ['user_id' => $userId, 'forum_id' => $forumId]);

            return;
        }

        $this->db->executeStatement(
            SqlDialect::insertIgnore(
                $this->db,
                'INSERT INTO forum_subscriptions (user_id, forum_id, created_at) VALUES (?, ?, ?)',
            ),
            [$userId, $forumId, gmdate('Y-m-d H:i:s.000000')],
        );
    }
}
