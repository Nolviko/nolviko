<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * Applies a permitted thread command atomically with its audit entry and outbox event (M03.08). The current
 * state is re-checked under the thread row lock.
 */
interface ThreadCommandWriter
{
    /**
     * Sets `is_sticky` or `is_locked`.
     *
     * @param string $flag ThreadCommands::STICKY or ThreadCommands::LOCK.
     *
     * @throws ThreadRuleViolation `thread_not_found` or `thread_state_unchanged`.
     */
    public function flag(
        int $threadId,
        string $flag,
        bool $value,
        int $actorId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void;

    /**
     * Moves the thread and both forums' statistics; with $leaveStub, a redirect stub stays in the old forum.
     *
     * @return int|null The stub's thread ID.
     *
     * @throws ThreadRuleViolation `thread_not_found`, `thread_move_same_forum` or `thread_move_target_invalid`.
     */
    public function move(
        int $threadId,
        int $targetForumId,
        bool $leaveStub,
        int $actorId,
        ?string $reason,
        DateTimeImmutable $at,
    ): ?int;

    /**
     * Moves posts of one thread into another (2026-11-08): selected posts to an existing thread, selected posts
     * to a new thread in $newForumId (split), or every post ($postIds null) to an existing thread (merge; the
     * emptied thread becomes a redirect stub). Threads, forum statistics and accepted solutions stay exact.
     *
     * @param list<int>|null $postIds
     * @return int The thread that received the posts.
     *
     * @throws ThreadRuleViolation `thread_not_found`, `thread_is_redirect`, `thread_posts_invalid`,
     *     `thread_posts_all`, `thread_merge_target_invalid` or `thread_move_target_invalid`.
     */
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
    ): int;
}
