<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * Threads and the thread lists. Every list takes the reader's ForumScope (M02.10, readable forums) and applies
 * it in SQL before `LIMIT`; each list is one query including author and last-poster names.
 */
interface ThreadRepository
{
    public const MAX_LIST = 100;

    /**
     * Stores a thread row (the create-thread service of M03.04 adds its first post and counters).
     *
     * @throws ThreadRuleViolation `thread_forum_not_found` or `thread_forum_is_category`.
     */
    public function create(
        int $forumId,
        ?int $userId,
        string $title,
        string $slug,
        DateTimeImmutable $createdAt,
        ThreadStatus $status = ThreadStatus::Visible,
    ): int;

    /**
     * Any thread by ID, whatever its status; callers check visibility and permissions.
     */
    public function find(int $id): ?Thread;

    /**
     * Visible threads of one forum: sticky first, then by last activity (index `ix_threads_forum_list`).
     * Empty when the forum is not in the scope. `$withDeleted` adds soft-deleted threads with their status as
     * the marker, for moderators (two statuses: the index no longer gives the order, so a sort of the forum's
     * threads is accepted on this moderator-only path).
     *
     * Keyset pagination (ADR-014, M03.11): `$after` returns the rows that follow that position (older), `$before`
     * the rows that precede it (newer), always in list order. Pass at most one.
     *
     * @return list<ThreadListItem>
     */
    public function forumThreads(
        ForumScope $scope,
        int $forumId,
        int $limit,
        bool $withDeleted = false,
        ?ThreadCursor $after = null,
        ?ThreadCursor $before = null,
    ): array;

    /**
     * A forum's threads in a member-chosen order (beta.18, roadmap 14): `title`, `replies` or `started`, sticky
     * threads first. Numbered pages (offset): the default last-activity order keeps keyset pagination.
     *
     * @return list<ThreadListItem>
     */
    public function forumThreadsSorted(
        ForumScope $scope,
        int $forumId,
        string $sort,
        int $limit,
        int $offset,
        bool $withDeleted = false,
    ): array;

    /**
     * The most recently active visible threads across the readable forums (index `ix_threads_latest`), without
     * moved-thread stubs. `$after` continues the list after that position (ADR-014 keyset; the cursor's sticky
     * flag is ignored because this list is not grouped by it). `$tagId` keeps the threads with that tag.
     *
     * @return list<ThreadListItem>
     */
    public function latest(ForumScope $scope, int $limit, ?ThreadCursor $after = null, ?int $tagId = null): array;

    /**
     * Visible threads in the readable forums, counted in SQL.
     */
    public function countVisible(ForumScope $scope): int;
}
