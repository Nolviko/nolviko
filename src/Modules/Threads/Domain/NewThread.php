<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * A validated, permitted thread with its first post, ready to be written in one transaction. Thread and post
 * share one status: a pending first post means a pending thread. `floodSeconds` is the subject's
 * `forum.post.flood_interval_seconds`, checked by the writer inside the transaction; `network` and
 * `networkSeconds` are the client network's bucket key and interval (M03.15).
 */
final class NewThread
{
    public function __construct(
        public readonly int $forumId,
        public readonly int $userId,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $content,
        public readonly string $contentFormat,
        public readonly string $renderedHtml,
        public readonly int $rendererVersion,
        public readonly ThreadStatus $threadStatus,
        public readonly PostStatus $postStatus,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $floodSeconds = 0,
        public readonly ?string $network = null,
        public readonly int $networkSeconds = 0,
    ) {
    }
}
