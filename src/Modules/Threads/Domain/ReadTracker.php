<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * Which threads a member has not read (M03.12). A thread is unread when its last post is newer than all of: the
 * member's last visit to it, the member's "mark forum read" time, the member's "mark all forums read" time
 * (beta.18) and the start of the read window. Guests have no read state.
 */
interface ReadTracker
{
    /** Threads whose last post is older than this count as read without any stored row. */
    public const WINDOW = '-30 days';

    /**
     * Records that the member has seen the thread up to a post; never moves backwards.
     */
    public function markThread(int $userId, int $threadId, int $lastPostId, DateTimeImmutable $at): void;

    /**
     * Marks every thread of the forum read and drops the member's per-thread rows there.
     */
    public function markForum(int $userId, int $forumId, DateTimeImmutable $at): void;

    /**
     * Marks every thread of every forum read with one board-wide cutoff (beta.18, roadmap 12) and drops the
     * member's per-thread and per-forum rows it supersedes. Never moves backwards.
     */
    public function markAll(int $userId, DateTimeImmutable $at): void;

    /**
     * The first visible post of the thread the member has not read (beta.18, roadmap 13): after the last post
     * the member saw there and newer than every cutoff. Null when the member has read the whole thread.
     */
    public function firstUnreadPost(int $userId, int $threadId, int $forumId, DateTimeImmutable $now): ?int;

    /**
     * @param array<int, ?DateTimeImmutable> $lastPosts Thread ID => time of its last post, all in $forumId.
     *
     * @return list<int> The unread thread IDs among them.
     */
    public function unread(int $userId, int $forumId, array $lastPosts, DateTimeImmutable $now): array;

    /**
     * The same answer for threads of several forums (the discussions home page), in two queries whatever the
     * number of forums: one for the forums' read cutoffs (with the board-wide one), one for the thread rows.
     *
     * @param array<int, array{int, ?DateTimeImmutable}> $threads Thread ID => [forum ID, time of its last post].
     *
     * @return list<int> The unread thread IDs among them.
     */
    public function unreadAcross(int $userId, array $threads, DateTimeImmutable $now): array;
}
