<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * Posts and revisions. Lists across threads take the reader's ForumScope (M02.10, readable forums) and apply it
 * in SQL before `LIMIT`; posts of hidden or pending threads never appear either.
 */
interface PostRepository
{
    public const MAX_LIST = 100;

    /** Characters kept for previews; the excerpt is cut on a character boundary, never inside one. */
    public const EXCERPT_LENGTH = 200;

    /**
     * Stores a post row (the create-post services of M03.04/M03.05 also update the thread).
     */
    public function add(
        int $threadId,
        ?int $userId,
        string $content,
        string $contentFormat,
        DateTimeImmutable $createdAt,
        PostStatus $status = PostStatus::Visible,
    ): int;

    public function find(int $id): ?Post;

    /**
     * Replaces the content and records the previous version as a revision, in one transaction. The cached
     * rendering is invalidated (`renderer_version` NULL).
     *
     * @return bool False when the post does not exist.
     */
    public function edit(
        int $postId,
        ?int $editorId,
        string $content,
        string $contentFormat,
        ?string $reason,
        DateTimeImmutable $editedAt,
    ): bool;

    /**
     * Saves a rendering of the given source. Nothing is written if the post changed meanwhile (its content no
     * longer equals $source), so an edit is never overwritten by a stale rendering.
     */
    public function storeRendering(int $postId, string $html, int $rendererVersion, string $source): void;

    /**
     * @return list<array{editor_id: int|null, previous_content: string, previous_format: string,
     *     change_reason: string|null, created_at: DateTimeImmutable}> Oldest first.
     */
    public function revisions(int $postId): array;

    /**
     * Visible posts of a thread in posting order (index `ix_posts_thread`).
     *
     * @return list<Post>
     */
    public function threadPosts(int $threadId, int $limit, int $afterId = 0, bool $withDeleted = false): array;

    /**
     * The newest visible posts in visible threads of the readable forums.
     *
     * @return list<PostPreview>
     */
    public function latest(ForumScope $scope, int $limit): array;

    /**
     * One member's newest visible posts in visible threads of the readable forums (profile page). Walks
     * `ix_posts_user` newest first; posts outside the reader's scope are skipped, never revealed.
     *
     * @return list<PostPreview>
     */
    public function latestByUser(ForumScope $scope, int $userId, int $limit): array;

    /**
     * First-post previews for the given threads, limited to readable forums and visible threads and posts.
     *
     * @param list<int> $threadIds
     *
     * @return array<int, PostPreview> Keyed by thread ID; unreadable threads are simply missing.
     */
    public function threadPreviews(ForumScope $scope, array $threadIds): array;

    /**
     * One numbered thread page (ADR-014): the visible posts at positions ($page - 1) * $perPage + 1 onwards, in
     * ID order. Positions count visible posts only, for everybody. With $withHidden, the pending and hidden posts
     * that lie between the previous page's last visible post and this page's last one (after it, on the last
     * page) are included too, marked by their status. $visibleTotal is the thread's stored visible-post count;
     * pages in the second half are read from the end of the index.
     *
     * @return list<PagedPost>
     */
    public function threadPage(
        int $threadId,
        int $page,
        int $perPage,
        int $visibleTotal,
        bool $withHidden = false,
    ): array;

    /**
     * How many visible posts of the post's thread come before it (ADR-014 permalinks), whatever the post's own
     * status. Counted on `ix_posts_thread` from the nearer end, which $firstPostId and $lastPostId estimate, and
     * $visibleTotal (the stored count) turns a count from the end into a position.
     */
    public function visibleBefore(Post $post, int $visibleTotal, ?int $firstPostId, ?int $lastPostId): int;

    /**
     * When the user last posted, in any status (the flood interval counts pending and hidden posts too).
     */
    public function lastPostAt(int $userId): ?DateTimeImmutable;

    /**
     * The user's visible posts, counted up to $cap (index `ix_posts_user` stops there).
     */
    public function visiblePostCount(int $userId, int $cap): int;

    /**
     * Whether the user has at least one visible post (`forum.post.approval` = `first_posts`).
     */
    public function hasVisiblePosts(int $userId): bool;
}
