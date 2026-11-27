<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * The dashboard's numbers (M04.01) from cheap sources only: the stored forum statistics for threads and posts
 * (never a COUNT over posts) and primary-key counts of users and forums.
 */
final class DbalAdminStats
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{users: int, forums: int, threads: int, posts: int}|null Null when the database failed; the
     *     dashboard then shows its error state instead of a server error.
     */
    public function read(): ?array
    {
        try {
            $totals = $this->connection->fetchAssociative(
                'SELECT COALESCE(SUM(thread_count), 0) AS threads, COALESCE(SUM(post_count), 0) AS posts '
                    . 'FROM forum_statistics',
            );

            return [
                'users' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'),
                'forums' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM forums WHERE type = 'forum'"),
                'threads' => (int) ($totals['threads'] ?? 0),
                'posts' => (int) ($totals['posts'] ?? 0),
            ];
        } catch (DbalException) {
            return null;
        }
    }

    /**
     * What waits for an administrator (2026-11-27, roadmap Beta 18): members waiting for approval, open reports,
     * posts waiting for approval and failed background tasks. Each count is bounded (at most 100 rows are read,
     * each on its status index); a failing count is left out.
     *
     * @return array<string, int> approvals, reports, posts, tasks
     */
    public function attention(): array
    {
        $queries = [
            'approvals' => "SELECT id FROM users WHERE status = 'pending_approval'",
            'reports' => "SELECT id FROM reports WHERE status = 'open'",
            'posts' => "SELECT id FROM posts WHERE status = 'pending'",
            'tasks' => "SELECT id FROM outbox_events WHERE status = 'failed'",
        ];
        $counts = [];
        foreach ($queries as $name => $sql) {
            try {
                $counts[$name] = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM (' . $sql . ' LIMIT 100) bounded_attention',
                );
            } catch (DbalException) {
                // That source is not available (older schema): no line for it.
            }
        }

        return $counts;
    }
}
