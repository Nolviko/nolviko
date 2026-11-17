<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;

/**
 * Moderator commands on a thread (M03.08): sticky/unsticky (`moderation.thread.sticky`), lock/unlock
 * (`moderation.thread.lock`) and move (`moderation.thread.move` in the thread's forum and in the target forum,
 * which the moderator must see and which must be a forum, not a category). CSRF is the kernel guard's job.
 *
 * A thread the subject may not read is not found; a member who can read it gets `moderation_denied` (403).
 * Redirect stubs take no commands (they can be deleted with ModerateContent).
 */
final class ThreadCommands
{
    public const STICKY = 'sticky';

    public const UNSTICKY = 'unsticky';

    public const LOCK = 'lock';

    public const UNLOCK = 'unlock';

    public const MOVE = 'move';

    public const COMMANDS = [self::STICKY, self::UNSTICKY, self::LOCK, self::UNLOCK, self::MOVE];

    /** Posts one split or move may select. */
    public const MAX_POSTS = 200;

    private const PERMISSIONS = [
        self::STICKY => 'moderation.thread.sticky',
        self::UNSTICKY => 'moderation.thread.sticky',
        self::LOCK => 'moderation.thread.lock',
        self::UNLOCK => 'moderation.thread.lock',
        self::MOVE => 'moderation.thread.move',
    ];

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly ThreadRepository $threads,
        private readonly ForumRepository $forums,
        private readonly ForumVisibility $visibility,
        private readonly ThreadCommandWriter $writer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /** The permission one command needs in the thread's forum (null for an unknown command). */
    public static function permissionFor(string $command): ?string
    {
        return self::PERMISSIONS[$command] ?? null;
    }

    /**
     * @param int|null $targetForumId Required for MOVE, ignored otherwise.
     *
     * @return int|null The redirect stub's thread ID after a move with $leaveStub.
     *
     * @throws ThreadRuleViolation `moderation_denied` (also for guests), `moderation_action_invalid`,
     *     `thread_not_found`, `thread_is_redirect`, `thread_move_target_invalid`, `thread_move_same_forum`,
     *     `thread_state_unchanged` or `post_reason_invalid`.
     */
    public function run(
        SubjectPermissions $subject,
        int $threadId,
        string $command,
        ?int $targetForumId = null,
        bool $leaveStub = false,
        string $reason = '',
    ): ?int {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');
        $permission = self::PERMISSIONS[$command] ?? throw new ThreadRuleViolation('moderation_action_invalid');
        $thread = $this->threads->find($threadId);

        if ($thread === null || !$this->visibility->readable($subject)->contains($thread->forumId)) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        if ($thread->status !== ThreadStatus::Visible && !ModerateContent::seesDeleted($subject, $thread->forumId)) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        if (!$subject->allows($permission, $thread->forumId)) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        if ($thread->movedToThreadId !== null) {
            throw new ThreadRuleViolation('thread_is_redirect');
        }

        $reason = EditPost::reason($reason);
        $now = ($this->clock)();

        if ($command !== self::MOVE) {
            $flag = $command === self::STICKY || $command === self::UNSTICKY ? self::STICKY : self::LOCK;
            $value = $command === self::STICKY || $command === self::LOCK;
            $this->writer->flag($threadId, $flag, $value, $userId, $reason, $now);

            return null;
        }

        $target = $targetForumId === null ? null : $this->forums->find($targetForumId);

        if (
            $target === null
            || $target->type !== ForumType::Forum
            || !$this->visibility->visible($subject)->contains($target->id)
            || !$subject->allows($permission, $target->id)
        ) {
            throw new ThreadRuleViolation('thread_move_target_invalid');
        }

        return $this->writer->move($threadId, $target->id, $leaveStub, $userId, $reason, $now);
    }

    /**
     * Split, move or merge posts (2026-11-08), with `moderation.thread.move` in the source forum and in the
     * forum that receives them. Exactly one destination: $targetThreadId (an existing thread) or $newForumId
     * with $newTitle (a new thread). With $all every post goes (merge into an existing thread only).
     *
     * @param list<int> $postIds Ignored with $all; otherwise 1 to MAX_POSTS IDs of the source thread.
     * @return int The thread that received the posts.
     *
     * @throws ThreadRuleViolation `moderation_denied`, `thread_not_found`, `thread_is_redirect`,
     *     `thread_posts_invalid`, `thread_posts_all`, `thread_merge_target_invalid`, `thread_move_target_invalid`,
     *     `thread_title_invalid` or `post_reason_invalid`.
     */
    public function movePosts(
        SubjectPermissions $subject,
        int $threadId,
        array $postIds,
        bool $all,
        ?int $targetThreadId,
        ?int $newForumId,
        string $newTitle,
        string $reason = '',
    ): int {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');
        $thread = $this->threads->find($threadId);
        $readable = $this->visibility->readable($subject);

        if ($thread === null || !$readable->contains($thread->forumId)) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        if (!$subject->allows(self::PERMISSIONS[self::MOVE], $thread->forumId)) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        if ($thread->movedToThreadId !== null) {
            throw new ThreadRuleViolation('thread_is_redirect');
        }

        $postIds = array_values(array_unique(array_filter($postIds, static fn(int $id): bool => $id > 0)));

        if (!$all && ($postIds === [] || count($postIds) > self::MAX_POSTS)) {
            throw new ThreadRuleViolation('thread_posts_invalid');
        }

        $reason = EditPost::reason($reason);
        $title = '';
        $slug = '';

        if ($targetThreadId !== null) {
            $target = $this->threads->find($targetThreadId);

            if (
                $target === null || $target->id === $thread->id || $target->movedToThreadId !== null
                || !$readable->contains($target->forumId)
                || !$subject->allows(self::PERMISSIONS[self::MOVE], $target->forumId)
            ) {
                throw new ThreadRuleViolation('thread_merge_target_invalid');
            }

            $newForumId = null;
        } else {
            $forum = $all || $newForumId === null ? null : $this->forums->find($newForumId);

            if (
                $forum === null || $forum->type !== ForumType::Forum
                || !$this->visibility->visible($subject)->contains($forum->id)
                || !$subject->allows(self::PERMISSIONS[self::MOVE], $forum->id)
            ) {
                throw new ThreadRuleViolation($all ? 'thread_merge_target_invalid' : 'thread_move_target_invalid');
            }

            $title = CreateThread::title($newTitle) ?? throw new ThreadRuleViolation('thread_title_invalid');
            $slug = CreateThread::slug($title);
        }

        return $this->writer->movePosts(
            $threadId,
            $all ? null : $postIds,
            $targetThreadId,
            $newForumId,
            $title,
            $slug,
            $userId,
            $reason,
            ($this->clock)(),
        );
    }
}
