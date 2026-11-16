<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

use DateTimeImmutable;

/**
 * A stored post. `content` is the source in `contentFormat` (ADR-012); `renderedHtml` is the cached output of
 * renderer `rendererVersion` (PostRenderer decides whether it is still current).
 */
final class Post
{
    public function __construct(
        public readonly int $id,
        public readonly int $threadId,
        public readonly ?int $userId,
        public readonly string $content,
        public readonly string $contentFormat,
        public readonly ?int $rendererVersion,
        public readonly PostStatus $status,
        public readonly ?DateTimeImmutable $editedAt,
        public readonly ?int $editedBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $renderedHtml = null,
    ) {
    }
}
