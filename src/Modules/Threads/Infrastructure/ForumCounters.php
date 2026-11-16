<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * Exact forum statistics for writers that change a thread's visibility or forum (M03.07, M03.08). Callers lock
 * the thread row and then the statistics rows (lockStatistics, ascending forum ID when there are two) before
 * their first plain read, take the thread's contribution before and after the change, and apply the
 * difference. A redirect stub contributes nothing.
 */
final class ForumCounters
{
    /**
     * @param list<int> $forumIds Locked in ascending order, so two writers never wait on each other in a cycle.
     */
    public static function lockStatistics(Connection $connection, array $forumIds): void
    {
        sort($forumIds);

        foreach ($forumIds as $forumId) {
            $connection->fetchOne('SELECT forum_id FROM forum_statistics WHERE forum_id = ? FOR UPDATE', [$forumId]);
        }
    }

    /**
     * What the thread adds to its forum's counters: one thread and its visible posts while it is visible and
     * not a redirect stub, nothing otherwise.
     *
     * @return array{int, int} Threads and posts.
     */
    public static function contribution(Connection $connection, int $threadId): array
    {
        $thread = $connection->fetchAssociative(
            'SELECT status, moved_to_thread_id FROM threads WHERE id = ?',
            [$threadId],
        );

        if ($thread === false || $thread['status'] !== 'visible' || $thread['moved_to_thread_id'] !== null) {
            return [0, 0];
        }

        $posts = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'",
            [$threadId],
        );

        return [1, $posts];
    }

    /**
     * Adds $after - $before to the forum's counters and recomputes its last post from its visible threads.
     *
     * @param array{int, int} $before
     * @param array{int, int} $after
     */
    public static function apply(Connection $connection, int $forumId, array $before, array $after, string $at): void
    {
        $last = $connection->fetchAssociative(
            "SELECT last_post_id, last_post_at FROM threads WHERE forum_id = ? AND status = 'visible' "
                . 'AND last_post_id IS NOT NULL ORDER BY last_post_at DESC, id DESC LIMIT 1',
            [$forumId],
        );
        $connection->executeStatement(
            'UPDATE forum_statistics SET thread_count = thread_count + ?, post_count = post_count + ?, '
                . 'last_post_id = ?, last_post_at = ?, updated_at = ? WHERE forum_id = ?',
            [
                $after[0] - $before[0],
                $after[1] - $before[1],
                $last === false ? null : $last['last_post_id'],
                $last === false ? null : $last['last_post_at'],
                $at,
                $forumId,
            ],
        );
    }
}
