<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ModerationWriter;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * ModerationWriter on DBAL. Each action is one transaction that locks, in the order every posting transaction
 * uses, the thread row, the post row and the forum statistics row, and only then reads. Under REPEATABLE READ
 * the first plain read fixes the snapshot, so it must see every reply committed before the locks were granted.
 *
 * Counters are not adjusted by guesswork: the thread's reply count and last post are recomputed from its posts
 * (index `ix_posts_thread`), and the forum receives the difference of the thread's contribution before and
 * after plus a fresh last post (ForumCounters).
 */
final class DbalModerationWriter implements ModerationWriter
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const PAST = [
        ModerateContent::DELETE => 'deleted',
        ModerateContent::RESTORE => 'restored',
        ModerateContent::APPROVE => 'approved',
        ModerateContent::PURGE => 'purged',
    ];

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function post(string $action, int $postId, int $actorId, ?string $reason, DateTimeImmutable $at): void
    {
        // Read outside the transaction, so the thread row can be locked before the post row.
        $threadId = $this->connection->fetchOne('SELECT thread_id FROM posts WHERE id = ?', [$postId]);

        if ($threadId === false) {
            throw new ThreadRuleViolation('post_not_found');
        }

        $this->connection->transactional(function (Connection $connection) use (
            $action,
            $postId,
            $threadId,
            $actorId,
            $reason,
            $at,
        ): void {
            $stamp = self::stamp($at);
            $thread = self::lockThread($connection, (int) $threadId);
            $post = $connection->fetchAssociative(
                'SELECT thread_id, status FROM posts WHERE id = ? FOR UPDATE',
                [$postId],
            );

            if ($post === false || (int) $post['thread_id'] !== (int) $threadId) {
                throw new ThreadRuleViolation('post_not_found');
            }

            self::assertState($action, (string) $post['status']);
            $forumId = (int) $thread['forum_id'];
            ForumCounters::lockStatistics($connection, [$forumId]);
            $before = ForumCounters::contribution($connection, (int) $threadId);

            match ($action) {
                ModerateContent::DELETE => self::softDelete($connection, 'posts', $postId, $actorId, $stamp),
                ModerateContent::RESTORE => self::restore($connection, 'posts', $postId),
                ModerateContent::APPROVE => $connection->update('posts', ['status' => 'visible'], ['id' => $postId]),
                default => $connection->delete('posts', ['id' => $postId]),
            };

            self::refreshThread($connection, (int) $threadId, (int) $thread['first_post_id']);
            $after = ForumCounters::contribution($connection, (int) $threadId);
            ForumCounters::apply($connection, $forumId, $before, $after, $stamp);
            $this->audit->record(
                'post.' . $action,
                'post.' . self::PAST[$action],
                'post',
                $postId,
                $actorId,
                $reason,
                $stamp,
                [
                    'thread_id' => (int) $threadId,
                    'forum_id' => $forumId,
                    'previous_status' => (string) $post['status'],
                ],
            );
        });
    }

    public function thread(string $action, int $threadId, int $actorId, ?string $reason, DateTimeImmutable $at): void
    {
        $this->connection->transactional(function (Connection $connection) use (
            $action,
            $threadId,
            $actorId,
            $reason,
            $at,
        ): void {
            $stamp = self::stamp($at);
            $thread = self::lockThread($connection, $threadId);
            self::assertState($action, (string) $thread['status']);
            $forumId = (int) $thread['forum_id'];
            ForumCounters::lockStatistics($connection, [$forumId]);
            $before = ForumCounters::contribution($connection, $threadId);

            if ($action === ModerateContent::PURGE) {
                // Clear the thread's post references first, so deleting the posts never updates the row
                // that is being deleted; revisions go with their posts (CASCADE).
                $connection->update('threads', ['first_post_id' => null, 'last_post_id' => null], ['id' => $threadId]);
                $connection->delete('posts', ['thread_id' => $threadId]);
                $connection->delete('threads', ['id' => $threadId]);
            } elseif ($action === ModerateContent::DELETE) {
                self::softDelete($connection, 'threads', $threadId, $actorId, $stamp);
            } elseif ($action === ModerateContent::APPROVE) {
                // A pending thread's first post waited with it; both are published together.
                $connection->update('threads', ['status' => 'visible'], ['id' => $threadId]);
                $connection->executeStatement(
                    "UPDATE posts SET status = 'visible' WHERE id = ? AND status = 'pending'",
                    [(int) $thread['first_post_id']],
                );
                self::refreshThread($connection, $threadId, (int) $thread['first_post_id']);
            } else {
                self::restore($connection, 'threads', $threadId);
            }

            $after = ForumCounters::contribution($connection, $threadId);
            ForumCounters::apply($connection, $forumId, $before, $after, $stamp);
            $this->audit->record(
                'thread.' . $action,
                'thread.' . self::PAST[$action],
                'thread',
                $threadId,
                $actorId,
                $reason,
                $stamp,
                [
                    'forum_id' => $forumId,
                    'previous_status' => (string) $thread['status'],
                ],
            );
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ThreadRuleViolation `thread_not_found`.
     */
    private static function lockThread(Connection $connection, int $threadId): array
    {
        $thread = $connection->fetchAssociative(
            'SELECT forum_id, status, first_post_id FROM threads WHERE id = ? FOR UPDATE',
            [$threadId],
        );

        return $thread === false ? throw new ThreadRuleViolation('thread_not_found') : $thread;
    }

    /**
     * @throws ThreadRuleViolation `content_already_deleted`, `content_not_deleted` or `content_not_pending`.
     */
    private static function assertState(string $action, string $status): void
    {
        $deleted = $status === 'hidden';

        if ($action === ModerateContent::APPROVE && $status !== 'pending') {
            throw new ThreadRuleViolation('content_not_pending');
        }

        if ($action === ModerateContent::DELETE && $deleted) {
            throw new ThreadRuleViolation('content_already_deleted');
        }

        if ($action === ModerateContent::RESTORE && !$deleted) {
            throw new ThreadRuleViolation('content_not_deleted');
        }
    }

    /**
     * `status_before_delete` is assigned before `status`: MySQL evaluates SET left to right with new values.
     */
    private static function softDelete(Connection $connection, string $table, int $id, int $actorId, string $at): void
    {
        $connection->executeStatement(
            'UPDATE ' . $table . " SET status_before_delete = status, status = 'hidden', deleted_at = ?, "
                . 'deleted_by = ?, updated_at = ? WHERE id = ?',
            [$at, $actorId, $at, $id],
        );
    }

    private static function restore(Connection $connection, string $table, int $id): void
    {
        $connection->executeStatement(
            'UPDATE ' . $table . " SET status = COALESCE(status_before_delete, 'visible'), "
                . 'status_before_delete = NULL, deleted_at = NULL, deleted_by = NULL WHERE id = ?',
            [$id],
        );
    }

    /**
     * Reply count and last post from the thread's visible posts. With none left the last-post columns keep
     * their values, so the thread keeps its place in lists.
     */
    private static function refreshThread(Connection $connection, int $threadId, int $firstPostId): void
    {
        $replies = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible' AND id <> ?",
            [$threadId, $firstPostId],
        );
        $last = $connection->fetchAssociative(
            "SELECT id, user_id, created_at FROM posts WHERE thread_id = ? AND status = 'visible' "
                . 'ORDER BY id DESC LIMIT 1',
            [$threadId],
        );
        $columns = ['reply_count' => $replies];

        if ($last !== false) {
            $columns += [
                'last_post_id' => (int) $last['id'],
                'last_post_at' => $last['created_at'],
                'last_poster_id' => $last['user_id'],
            ];
        }

        $connection->update('threads', $columns, ['id' => $threadId]);
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
