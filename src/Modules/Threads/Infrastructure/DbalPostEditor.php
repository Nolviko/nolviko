<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Threads\Domain\EditedPost;
use Nolviko\Modules\Threads\Domain\PostEditor;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * PostEditor on DBAL: PostRepository::edit() (row lock, revision, new content) runs inside this transaction as
 * a savepoint, followed by the rendering of the new source and the outbox event. The event's dedupe key is the
 * revision ID, so every edit is its own event.
 */
final class DbalPostEditor implements PostEditor
{
    public const EVENT = 'post.edited';

    public function __construct(
        private readonly Connection $connection,
        private readonly PostRepository $posts,
        private readonly Outbox $outbox,
    ) {
    }

    public function edit(EditedPost $edit): void
    {
        $this->connection->transactional(function (Connection $connection) use ($edit): void {
            $stored = $this->posts->edit(
                $edit->postId,
                $edit->editorId,
                $edit->content,
                $edit->contentFormat,
                $edit->reason,
                $edit->editedAt,
            );

            if (!$stored) {
                throw new ThreadRuleViolation('post_not_found');
            }

            $this->posts->storeRendering($edit->postId, $edit->renderedHtml, $edit->rendererVersion, $edit->content);
            $revision = (int) $connection->fetchOne(
                'SELECT MAX(id) FROM post_revisions WHERE post_id = ?',
                [$edit->postId],
            );
            $this->outbox->record(self::EVENT, 'post', $edit->postId, [
                'post_id' => $edit->postId,
                'revision_id' => $revision,
                'editor_id' => $edit->editorId,
            ], self::EVENT . ':' . $revision);
        });
    }

    public function retitle(int $threadId, string $title, string $slug, int $editorId, DateTimeImmutable $at): void
    {
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->transactional(function (Connection $connection) use (
            $threadId,
            $title,
            $slug,
            $editorId,
            $stamp,
        ): void {
            $old = $connection->fetchOne('SELECT title FROM threads WHERE id = ? FOR UPDATE', [$threadId]);
            $connection->update(
                'threads',
                ['title' => $title, 'slug' => $slug, 'updated_at' => $stamp],
                ['id' => $threadId],
            );
            $connection->insert('audit_logs', ['actor_id' => $editorId, 'action' => 'thread.retitle',
                'target_type' => 'thread', 'target_id' => $threadId, 'reason' => null,
                'details' => json_encode(['from' => (string) $old, 'to' => $title], JSON_THROW_ON_ERROR),
                'created_at' => $stamp]);
        });
    }
}
