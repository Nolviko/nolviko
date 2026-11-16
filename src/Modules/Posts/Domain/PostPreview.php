<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

use DateTimeImmutable;

/**
 * A post as shown in "latest posts" and thread previews: an excerpt of the source, never more of a post than
 * the reader may see.
 */
final class PostPreview
{
    public function __construct(
        public readonly int $postId,
        public readonly int $threadId,
        public readonly int $forumId,
        public readonly string $threadTitle,
        public readonly string $excerpt,
        public readonly ?string $authorName,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
