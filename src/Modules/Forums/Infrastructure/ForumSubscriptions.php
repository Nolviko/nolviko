<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
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

    /**
     * The member's subscribed forums among `$visible` (forum IDs the member may see), by name.
     *
     * @param list<int> $visible
     * @return list<array{id: int, title: string}>
     */
    public function forums(int $userId, array $visible): array
    {
        if ($visible === []) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT f.id, f.title FROM forum_subscriptions s INNER JOIN forums f ON f.id = s.forum_id '
                    . 'WHERE s.user_id = ? AND s.forum_id IN (?) ORDER BY f.title, f.id LIMIT 200',
                [$userId, $visible],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'],
            'title' => (string) $row['title']], $rows);
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
