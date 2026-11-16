<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * The status of new content from `forum.post.approval`: `all` holds everything back, `first_posts` holds back
 * until the user has $firstPosts visible posts (M03.15a, `posting.first_posts_moderated`), `none` publishes at
 * once. Shared by CreateThread and CreateReply.
 */
final class PostApproval
{
    public const KEY = 'forum.post.approval';

    public function __construct(
        private readonly PostRepository $posts,
        private readonly int $firstPosts = 1,
    ) {
    }

    public function status(SubjectPermissions $subject, int $userId, int $forumId): PostStatus
    {
        return match ($subject->value(self::KEY, $forumId)) {
            'none' => PostStatus::Visible,
            'first_posts' => $this->posts->visiblePostCount($userId, $this->firstPosts) >= $this->firstPosts
                ? PostStatus::Visible
                : PostStatus::Pending,
            default => PostStatus::Pending,
        };
    }
}
