<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadCommandWriter;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * ThreadCommandWriter on DBAL. A move locks the thread row, then both forum statistics rows in ascending forum
 * order (ForumCounters), and only then reads; the thread's contribution leaves the old forum and arrives in the
 * new one, and both forums get a fresh last post. The optional stub is a post-less thread row in the old forum
 * whose `moved_to_thread_id` points at the moved thread; it keeps the title and last activity so it sorts where
 * the thread was, and is never counted.
 */
final class DbalThreadCommandWriter implements ThreadCommandWriter
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const COLUMNS = [
        ThreadCommands::STICKY => 'is_sticky',
        ThreadCommands::LOCK => 'is_locked',
    ];

    /** Audit action and outbox event for setting and for clearing each flag. */
    private const ACTIONS = [
        ThreadCommands::STICKY => [['thread.sticky', 'thread.stickied'], ['thread.unsticky', 'thread.unstickied']],
        ThreadCommands::LOCK => [['thread.lock', 'thread.locked'], ['thread.unlock', 'thread.unlocked']],
    ];

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function flag(
        int $threadId,
        string $flag,
        bool $value,
        int $actorId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void {
        $column = self::COLUMNS[$flag];
        $this->connection->transactional(function (Connection $connection) use (
            $threadId,
            $flag,
            $column,
            $value,
            $actorId,
            $reason,
            $at,
        ): void {
            $thread = self::lockThread($connection, $threadId);

            if (((int) $thread[$column] === 1) === $value) {
                throw new ThreadRuleViolation('thread_state_unchanged');
            }

            $stamp = self::stamp($at);
            $connection->update('threads', [$column => $value ? 1 : 0, 'updated_at' => $stamp], ['id' => $threadId]);
            [$action, $event] = self::ACTIONS[$flag][$value ? 0 : 1];
            $this->audit->record($action, $event, 'thread', $threadId, $actorId, $reason, $stamp, [
                'forum_id' => (int) $thread['forum_id'],
                $column => $value,
            ]);
        });
    }

    public function move(
        int $threadId,
        int $targetForumId,
        bool $leaveStub,
        int $actorId,
        ?string $reason,
        DateTimeImmutable $at,
    ): ?int {
        return $this->connection->transactional(function (Connection $connection) use (
            $threadId,
            $targetForumId,
            $leaveStub,
            $actorId,
            $reason,
            $at,
        ): ?int {
            $thread = self::lockThread($connection, $threadId);
            $sourceForumId = (int) $thread['forum_id'];

            if ($sourceForumId === $targetForumId) {
                throw new ThreadRuleViolation('thread_move_same_forum');
            }

            // A shared lock: the target cannot be deleted or turned into a category until this move commits.
            // MariaDB has no FOR SHARE and PostgreSQL no LOCK IN SHARE MODE (SqlDialect::shareLock).
            $type = $connection->fetchOne(
                'SELECT type FROM forums WHERE id = ? ' . SqlDialect::shareLock($connection),
                [$targetForumId],
            );

            if ($type !== 'forum') {
                throw new ThreadRuleViolation('thread_move_target_invalid');
            }

            ForumCounters::lockStatistics($connection, [$sourceForumId, $targetForumId]);
            $contribution = ForumCounters::contribution($connection, $threadId);
            $stamp = self::stamp($at);
            $connection->update('threads', ['forum_id' => $targetForumId, 'updated_at' => $stamp], ['id' => $threadId]);
            $stubId = $leaveStub ? self::insertStub($connection, $thread, $sourceForumId, $threadId, $stamp) : null;
            ForumCounters::apply($connection, $sourceForumId, $contribution, [0, 0], $stamp);
            ForumCounters::apply($connection, $targetForumId, [0, 0], $contribution, $stamp);
            $this->audit->record('thread.move', 'thread.moved', 'thread', $threadId, $actorId, $reason, $stamp, [
                'from_forum_id' => $sourceForumId,
                'to_forum_id' => $targetForumId,
                'stub_thread_id' => $stubId,
            ]);

            return $stubId;
        });
    }

    public function movePosts(
        int $sourceThreadId,
        ?array $postIds,
        ?int $targetThreadId,
        ?int $newForumId,
        string $newTitle,
        string $newSlug,
        int $actorId,
        ?string $reason,
        DateTimeImmutable $at,
    ): int {
        return $this->connection->transactional(function (Connection $connection) use (
            $sourceThreadId,
            $postIds,
            $targetThreadId,
            $newForumId,
            $newTitle,
            $newSlug,
            $actorId,
            $reason,
            $at,
        ): int {
            $stamp = self::stamp($at);
            $locked = $targetThreadId === null ? [$sourceThreadId] : [$sourceThreadId, $targetThreadId];
            sort($locked);
            $rows = [];

            foreach (array_unique($locked) as $id) {
                $rows[$id] = self::lockThread($connection, $id);
            }

            $source = $rows[$sourceThreadId];

            if ($source['moved_to_thread_id'] !== null) {
                throw new ThreadRuleViolation('thread_is_redirect');
            }

            if (
                $targetThreadId !== null
                && ($targetThreadId === $sourceThreadId || $rows[$targetThreadId]['moved_to_thread_id'] !== null)
            ) {
                throw new ThreadRuleViolation('thread_merge_target_invalid');
            }

            $moving = array_map(intval(...), $postIds === null
                ? $connection->fetchFirstColumn(
                    'SELECT id FROM posts WHERE thread_id = ? ORDER BY id FOR UPDATE',
                    [$sourceThreadId],
                )
                : $connection->fetchFirstColumn(
                    'SELECT id FROM posts WHERE thread_id = ? AND id IN (?) ORDER BY id FOR UPDATE',
                    [$sourceThreadId, $postIds],
                    [ParameterType::INTEGER, ArrayParameterType::INTEGER],
                ));

            if ($moving === [] || ($postIds !== null && count($moving) !== count(array_unique($postIds)))) {
                throw new ThreadRuleViolation('thread_posts_invalid');
            }

            $left = (int) $connection->fetchOne('SELECT COUNT(*) FROM posts WHERE thread_id = ?', [$sourceThreadId])
                - count($moving);

            if ($postIds !== null && $left === 0) {
                throw new ThreadRuleViolation('thread_posts_all');
            }

            if ($targetThreadId === null) {
                $type = $connection->fetchOne(
                    'SELECT type FROM forums WHERE id = ? ' . SqlDialect::shareLock($connection),
                    [$newForumId],
                );

                if ($type !== 'forum') {
                    throw new ThreadRuleViolation('thread_move_target_invalid');
                }

                $first = $connection->fetchAssociative(
                    'SELECT user_id, created_at FROM posts WHERE id = ?',
                    [$moving[0]],
                );
                $connection->insert('threads', [
                    'forum_id' => $newForumId,
                    'user_id' => $first === false ? null : $first['user_id'],
                    'title' => $newTitle,
                    'slug' => $newSlug,
                    'status' => 'visible',
                    'last_post_at' => $first === false ? $stamp : $first['created_at'],
                    'created_at' => $first === false ? $stamp : $first['created_at'],
                    'updated_at' => $stamp,
                ]);
                $targetThreadId = (int) $connection->lastInsertId();
                $targetForumId = (int) $newForumId;
            } else {
                $targetForumId = (int) $rows[$targetThreadId]['forum_id'];
            }

            $sourceForumId = (int) $source['forum_id'];
            ForumCounters::lockStatistics($connection, array_values(array_unique([$sourceForumId, $targetForumId])));
            // A thread created above counted nothing before the move.
            $before = [
                ForumCounters::contribution($connection, $sourceThreadId),
                $newForumId === null ? ForumCounters::contribution($connection, $targetThreadId) : [0, 0],
            ];
            $connection->executeStatement(
                'UPDATE posts SET thread_id = ? WHERE id IN (?)',
                [$targetThreadId, $moving],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
            $connection->executeStatement(
                'DELETE FROM thread_solutions WHERE thread_id = ? AND post_id IN (?)',
                [$sourceThreadId, $moving],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );

            if ($left === 0) {
                // Merged: the emptied thread becomes a redirect stub, so its links and bookmarks still lead on.
                $connection->executeStatement(
                    SqlDialect::insertIgnore(
                        $connection,
                        'INSERT INTO thread_subscriptions (user_id, thread_id, email_optin, created_at) '
                            . 'SELECT user_id, ?, email_optin, created_at FROM thread_subscriptions '
                            . 'WHERE thread_id = ?',
                    ),
                    [$targetThreadId, $sourceThreadId],
                );
                $connection->update('threads', ['moved_to_thread_id' => $targetThreadId, 'first_post_id' => null,
                    'last_post_id' => null, 'reply_count' => 0, 'updated_at' => $stamp], ['id' => $sourceThreadId]);
            } else {
                self::refreshPosts($connection, $sourceThreadId, $stamp);
            }

            self::refreshPosts($connection, $targetThreadId, $stamp);
            $after = [
                ForumCounters::contribution($connection, $sourceThreadId),
                ForumCounters::contribution($connection, $targetThreadId),
            ];

            if ($sourceForumId === $targetForumId) {
                ForumCounters::apply($connection, $sourceForumId, [$before[0][0] + $before[1][0],
                    $before[0][1] + $before[1][1]], [$after[0][0] + $after[1][0], $after[0][1] + $after[1][1]], $stamp);
            } else {
                ForumCounters::apply($connection, $sourceForumId, $before[0], $after[0], $stamp);
                ForumCounters::apply($connection, $targetForumId, $before[1], $after[1], $stamp);
            }

            $this->audit->record('posts.move', 'posts.moved', 'thread', $sourceThreadId, $actorId, $reason, $stamp, [
                'from_thread_id' => $sourceThreadId,
                'to_thread_id' => $targetThreadId,
                'post_ids' => array_slice($moving, 0, 200),
                'merged' => $left === 0,
                'new_thread' => $newForumId !== null,
            ]);

            return $targetThreadId;
        });
    }

    /**
     * First post, reply count and last post of a thread from its posts (ID order is posting order).
     */
    private static function refreshPosts(Connection $connection, int $threadId, string $stamp): void
    {
        $first = (int) $connection->fetchOne(
            'SELECT id FROM posts WHERE thread_id = ? ORDER BY id LIMIT 1',
            [$threadId],
        );
        $replies = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible' AND id <> ?",
            [$threadId, $first],
        );
        $last = $connection->fetchAssociative(
            "SELECT id, user_id, created_at FROM posts WHERE thread_id = ? AND status = 'visible' "
                . 'ORDER BY id DESC LIMIT 1',
            [$threadId],
        );
        $columns = ['first_post_id' => $first, 'reply_count' => $replies, 'updated_at' => $stamp];

        if ($last !== false) {
            $columns += ['last_post_id' => (int) $last['id'], 'last_post_at' => $last['created_at'],
                'last_poster_id' => $last['user_id']];
        }

        $connection->update('threads', $columns, ['id' => $threadId]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ThreadRuleViolation `thread_not_found`.
     */
    private static function lockThread(Connection $connection, int $threadId): array
    {
        $thread = $connection->fetchAssociative(
            'SELECT forum_id, user_id, title, slug, status, is_sticky, is_locked, last_post_at, last_poster_id, '
                . 'created_at, moved_to_thread_id FROM threads WHERE id = ? FOR UPDATE',
            [$threadId],
        );

        return $thread === false ? throw new ThreadRuleViolation('thread_not_found') : $thread;
    }

    /**
     * @param array<string, mixed> $thread The locked row of the moved thread.
     */
    private static function insertStub(
        Connection $connection,
        array $thread,
        int $forumId,
        int $threadId,
        string $at,
    ): int {
        $connection->insert('threads', [
            'forum_id' => $forumId,
            'user_id' => $thread['user_id'],
            'title' => $thread['title'],
            'slug' => $thread['slug'],
            'status' => $thread['status'],
            'last_post_at' => $thread['last_post_at'],
            'last_poster_id' => $thread['last_poster_id'],
            'moved_to_thread_id' => $threadId,
            'created_at' => $thread['created_at'],
            'updated_at' => $at,
        ]);

        return (int) $connection->lastInsertId();
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
