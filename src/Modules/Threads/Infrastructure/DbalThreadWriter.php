<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\CreatedThread;
use Nolviko\Modules\Threads\Domain\NewThread;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadWriter;

/**
 * ThreadWriter on DBAL: everything in one transaction on the connection the thread repository and the outbox
 * share. The post row is inserted with its rendering, so the first view needs no write.
 */
final class DbalThreadWriter implements ThreadWriter
{
    public const EVENT = 'thread.created';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
        private readonly ThreadRepository $threads,
        private readonly Outbox $outbox,
        private readonly PostingLock $posting,
    ) {
    }

    public function create(NewThread $thread): CreatedThread
    {
        $this->posting->prepare(
            $thread->userId,
            $thread->floodSeconds,
            $thread->network,
            $thread->networkSeconds,
            $thread->createdAt,
        );

        return $this->connection->transactional(function (Connection $connection) use ($thread): CreatedThread {
            // First, before any plain read fixes the snapshot (PostingLock).
            $this->posting->lockUser($thread->userId);
            $this->posting->assertWaited(
                $thread->userId,
                $thread->floodSeconds,
                $thread->createdAt,
                $thread->network,
                $thread->networkSeconds,
            );
            $at = $thread->createdAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
            $threadId = $this->threads->create(
                $thread->forumId,
                $thread->userId,
                $thread->title,
                $thread->slug,
                $thread->createdAt,
                $thread->threadStatus,
            );
            $connection->insert('posts', [
                'thread_id' => $threadId,
                'user_id' => $thread->userId,
                'content' => $thread->content,
                'content_format' => $thread->contentFormat,
                'renderer_version' => $thread->rendererVersion,
                'rendered_html' => $thread->renderedHtml,
                'status' => $thread->postStatus->value,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $postId = (int) $connection->lastInsertId();
            $connection->update(
                'threads',
                ['first_post_id' => $postId, 'last_post_id' => $postId],
                ['id' => $threadId],
            );

            if ($thread->postStatus === PostStatus::Visible) {
                self::countVisible($connection, $thread->forumId, $postId, $at);
            }

            $this->outbox->record(self::EVENT, 'thread', $threadId, [
                'thread_id' => $threadId,
                'post_id' => $postId,
                'forum_id' => $thread->forumId,
                'user_id' => $thread->userId,
                'status' => $thread->threadStatus->value,
            ]);

            return new CreatedThread($threadId, $postId, $thread->slug, $thread->threadStatus);
        });
    }

    /**
     * The statistics row exists from forum creation (or the 0014 backfill); the insert is only a fallback.
     * The UPDATE row-locks it, so concurrent threads in one forum count exactly.
     */
    private static function countVisible(Connection $connection, int $forumId, int $postId, string $at): void
    {
        $updated = $connection->executeStatement(
            'UPDATE forum_statistics SET thread_count = thread_count + 1, post_count = post_count + 1, '
                . 'last_post_id = ?, last_post_at = ?, updated_at = ? WHERE forum_id = ?',
            [$postId, $at, $at, $forumId],
        );

        if ($updated === 0) {
            $connection->insert('forum_statistics', [
                'forum_id' => $forumId,
                'thread_count' => 1,
                'post_count' => 1,
                'last_post_id' => $postId,
                'last_post_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }
}
