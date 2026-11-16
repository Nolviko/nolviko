<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\CreatedReply;
use Nolviko\Modules\Threads\Domain\NewReply;
use Nolviko\Modules\Threads\Domain\ReplyWriter;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;

/**
 * ReplyWriter on DBAL. One transaction, locks in the PostingLock order: user row, idempotency key, thread row
 * (FOR UPDATE, before the post insert, so the foreign-key check never has to upgrade a shared lock), then the
 * forum statistics row. A duplicate key rolls everything back and answers with the post of the first submit.
 */
final class DbalReplyWriter implements ReplyWriter
{
    public const EVENT = 'post.created';

    public const SCOPE = 'post.reply';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly PostingLock $posting,
    ) {
    }

    public function reply(NewReply $reply): CreatedReply
    {
        $canRetry = !$this->connection->isTransactionActive();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->posting->prepare(
                $reply->userId,
                $reply->floodSeconds,
                $reply->network,
                $reply->networkSeconds,
                $reply->createdAt,
            );

            try {
                return $this->connection->transactional(fn(Connection $connection): CreatedReply => $this->write(
                    $connection,
                    $reply,
                ));
            } catch (UniqueConstraintViolationException) {
                return $this->repeated($reply) ?? throw new ThreadRuleViolation('post_token_invalid');
            } catch (RetryableException $failure) {
                // DBAL rolls back the complete reply (post, counters, key and outbox) before retrying.
                if (!$canRetry || $attempt === 2) {
                    throw $failure;
                }

                usleep(random_int(1000, 10000));
            }
        }

        throw new LogicException('reply_retry_exhausted');
    }

    private function write(Connection $connection, NewReply $reply): CreatedReply
    {
        $at = $reply->createdAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
        $this->posting->lockUser($reply->userId);
        $connection->insert('idempotency_keys', [
            'user_id' => $reply->userId,
            'scope' => self::SCOPE,
            'token' => $reply->token,
            'created_at' => $at,
        ]);
        $keyId = (int) $connection->lastInsertId();
        $this->posting->assertWaited(
            $reply->userId,
            $reply->floodSeconds,
            $reply->createdAt,
            $reply->network,
            $reply->networkSeconds,
        );
        $thread = $connection->fetchAssociative(
            'SELECT forum_id, status, is_locked FROM threads WHERE id = ? FOR UPDATE',
            [$reply->threadId],
        );

        if (
            $thread === false
            || $thread['status'] !== ThreadStatus::Visible->value
            || (int) $thread['forum_id'] !== $reply->forumId
        ) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        if ((int) $thread['is_locked'] === 1 && !$reply->lockBypass) {
            throw new ThreadRuleViolation('thread_locked');
        }

        $forumId = (int) $thread['forum_id'];
        $connection->insert('posts', [
            'thread_id' => $reply->threadId,
            'user_id' => $reply->userId,
            'content' => $reply->content,
            'content_format' => $reply->contentFormat,
            'renderer_version' => $reply->rendererVersion,
            'rendered_html' => $reply->renderedHtml,
            'status' => $reply->status->value,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $postId = (int) $connection->lastInsertId();

        if ($reply->status === PostStatus::Visible) {
            $connection->executeStatement(
                'UPDATE threads SET reply_count = reply_count + 1, last_post_id = ?, last_post_at = ?, '
                    . 'last_poster_id = ?, updated_at = ? WHERE id = ?',
                [$postId, $at, $reply->userId, $at, $reply->threadId],
            );
            $connection->executeStatement(
                'UPDATE forum_statistics SET post_count = post_count + 1, last_post_id = ?, last_post_at = ?, '
                    . 'updated_at = ? WHERE forum_id = ?',
                [$postId, $at, $at, $forumId],
            );
        }

        $connection->update('idempotency_keys', ['result_id' => $postId], ['id' => $keyId]);
        $this->outbox->record(self::EVENT, 'post', $postId, [
            'post_id' => $postId,
            'thread_id' => $reply->threadId,
            'forum_id' => $forumId,
            'user_id' => $reply->userId,
            'status' => $reply->status->value,
        ]);

        return new CreatedReply($reply->threadId, $postId, $reply->status);
    }

    /**
     * The first submit's post when this token was already used for this thread; null otherwise.
     */
    private function repeated(NewReply $reply): ?CreatedReply
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.status FROM idempotency_keys k JOIN posts p ON p.id = k.result_id '
                . 'WHERE k.user_id = ? AND k.scope = ? AND k.token = ? AND p.thread_id = ?',
            [$reply->userId, self::SCOPE, $reply->token, $reply->threadId],
        );

        return $row === false
            ? null
            : new CreatedReply($reply->threadId, (int) $row['id'], PostStatus::from((string) $row['status']), true);
    }
}
