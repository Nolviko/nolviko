<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * Soft delete, restore and hard delete of posts and threads (M03.07).
 *
 * - Delete (soft): moderators (`moderation.post.hide` in the forum) any post or thread; authors their own
 *   replies with `forum.post.delete_own` while the thread is not locked. The first post goes with its thread.
 * - Restore: moderators only.
 * - Approve (M03.15a): moderators publish pending content; a pending thread is approved with its first post.
 * - Purge (hard delete): administrators only (`admin.access`); the audit entry survives it.
 *
 * Content the subject may not see is reported as not found; moderators see soft-deleted content (seesDeleted()).
 */
final class ModerateContent
{
    public const DELETE = 'delete';

    public const RESTORE = 'restore';

    public const APPROVE = 'approve';

    public const PURGE = 'purge';

    public const ACTIONS = [self::DELETE, self::RESTORE, self::PURGE, self::APPROVE];

    public const MODERATE = 'moderation.post.hide';

    public const DELETE_OWN = 'forum.post.delete_own';

    public const ADMIN = 'admin.access';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly PostRepository $posts,
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly ModerationWriter $writer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * Whether lists for this subject include soft-deleted content, marked by its status.
     */
    public static function seesDeleted(SubjectPermissions $subject, int $forumId): bool
    {
        return $subject->allows(self::MODERATE, $forumId);
    }

    /**
     * @throws ThreadRuleViolation `moderation_denied` (also for guests), `moderation_action_invalid`,
     *     `post_not_found`, `post_is_first`, `thread_locked`, `content_already_deleted`, `content_not_deleted`,
     *     `content_not_pending` or `post_reason_invalid`.
     */
    public function post(SubjectPermissions $subject, int $postId, string $action, string $reason = ''): void
    {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');
        self::assertAction($action);
        $post = $this->posts->find($postId);
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        if ($post === null || $thread === null || !$this->visibility->readable($subject)->contains($thread->forumId)) {
            throw new ThreadRuleViolation('post_not_found');
        }

        $moderator = self::seesDeleted($subject, $thread->forumId);
        $own = $post->userId === $userId;

        if (
            !$moderator
            && ($thread->status !== ThreadStatus::Visible || ($post->status !== PostStatus::Visible && !$own))
        ) {
            throw new ThreadRuleViolation('post_not_found');
        }

        if ($action !== self::RESTORE && $post->id === $thread->firstPostId) {
            throw new ThreadRuleViolation('post_is_first');
        }

        $allowed = match ($action) {
            self::DELETE => $moderator || $this->authorMayDelete($subject, $own, $thread),
            self::RESTORE, self::APPROVE => $moderator,
            default => $subject->allows(self::ADMIN),
        };

        if (!$allowed) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        $this->writer->post($action, $postId, $userId, EditPost::reason($reason), ($this->clock)());
    }

    /**
     * @throws ThreadRuleViolation `moderation_denied` (also for guests), `moderation_action_invalid`,
     *     `thread_not_found`, `content_already_deleted`, `content_not_deleted` or `post_reason_invalid`.
     */
    public function thread(SubjectPermissions $subject, int $threadId, string $action, string $reason = ''): void
    {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');
        self::assertAction($action);
        $thread = $this->threads->find($threadId);

        if ($thread === null || !$this->visibility->readable($subject)->contains($thread->forumId)) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        $moderator = self::seesDeleted($subject, $thread->forumId);

        if (!$moderator && $thread->status !== ThreadStatus::Visible) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        $allowed = $action === self::PURGE ? $subject->allows(self::ADMIN) : $moderator;

        if (!$allowed) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        $this->writer->thread($action, $threadId, $userId, EditPost::reason($reason), ($this->clock)());
    }

    private function authorMayDelete(SubjectPermissions $subject, bool $own, Thread $thread): bool
    {
        if (!$own || !$subject->allows(self::DELETE_OWN, $thread->forumId)) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        if ($thread->isLocked) {
            throw new ThreadRuleViolation('thread_locked');
        }

        return true;
    }

    private static function assertAction(string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }
    }
}
