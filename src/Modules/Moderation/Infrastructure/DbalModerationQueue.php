<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Moderation\Domain\ModerationQueue;
use Nolviko\Modules\Moderation\Domain\QueueItem;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;

/**
 * ModerationQueue on DBAL. Each list walks its status index newest first (`ix_reports_queue`, `ix_posts_status`,
 * `ix_users_status`), joins by primary key and stops at the LIMIT; the forum scope is a condition of the same
 * statement. Excerpts are the first 200 characters of the source, escaped by the template.
 */
final class DbalModerationQueue implements ModerationQueue
{
    private const EXCERPT = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function openReports(ForumScope $scope, int $limit, ?int $before = null): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.created_at, r.reason, p.id AS post_id, t.id AS thread_id, t.title, '
                . 'LEFT(p.content, ' . self::EXCERPT . ') AS excerpt, author.username AS author_name, '
                . 'reporter.username AS reporter_name FROM reports r JOIN posts p ON p.id = r.post_id '
                . 'JOIN threads t ON t.id = p.thread_id LEFT JOIN users author ON author.id = p.user_id '
                . 'LEFT JOIN users reporter ON reporter.id = r.reporter_id '
                . "WHERE r.status = 'open' AND r.id < ? AND " . $condition
                . ' ORDER BY r.id DESC LIMIT ' . self::limit($limit),
            [$before ?? PHP_INT_MAX, ...$parameters],
            [ParameterType::INTEGER, ...$types],
        );

        return array_map(static fn(array $row): QueueItem => new QueueItem(
            (int) $row['id'],
            self::time((string) $row['created_at']),
            (int) $row['post_id'],
            (int) $row['thread_id'],
            (string) $row['title'],
            (string) $row['excerpt'],
            $row['author_name'] === null ? null : (string) $row['author_name'],
            (string) $row['reason'],
            $row['reporter_name'] === null ? null : (string) $row['reporter_name'],
        ), $rows);
    }

    public function pendingPosts(ForumScope $scope, int $limit, ?int $before = null): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.created_at, t.id AS thread_id, t.title, LEFT(p.content, ' . self::EXCERPT . ') AS excerpt, '
                . 'author.username AS author_name FROM posts p JOIN threads t ON t.id = p.thread_id '
                . 'LEFT JOIN users author ON author.id = p.user_id '
                . "WHERE p.status = 'pending' AND p.id < ? AND t.status IN ('visible', 'pending') AND " . $condition
                . ' ORDER BY p.id DESC LIMIT ' . self::limit($limit),
            [$before ?? PHP_INT_MAX, ...$parameters],
            [ParameterType::INTEGER, ...$types],
        );

        return array_map(static fn(array $row): QueueItem => new QueueItem(
            (int) $row['id'],
            self::time((string) $row['created_at']),
            (int) $row['id'],
            (int) $row['thread_id'],
            (string) $row['title'],
            (string) $row['excerpt'],
            $row['author_name'] === null ? null : (string) $row['author_name'],
        ), $rows);
    }

    public function pendingUsers(int $limit, ?int $before = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, username, email, created_at FROM users WHERE status = 'pending_approval' AND id < ? "
                . 'ORDER BY id DESC LIMIT ' . self::limit($limit),
            [$before ?? PHP_INT_MAX],
            [ParameterType::INTEGER],
        );

        return array_map(static fn(array $row): QueueItem => new QueueItem(
            (int) $row['id'],
            self::time((string) $row['created_at']),
            username: (string) $row['username'],
            email: (string) $row['email'],
        ), $rows);
    }

    private static function limit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    private static function time(string $stored): DateTimeImmutable
    {
        return new DateTimeImmutable($stored, new DateTimeZone('UTC'));
    }
}
