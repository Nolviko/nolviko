<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use DateTimeImmutable;

/**
 * One row of the moderator queue (M04.07): an open report, a pending post or an account waiting for approval.
 * `id` is the keyset position (report, post or user ID); the other fields are what the row shows, null when they
 * do not apply to its kind.
 */
final class QueueItem
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $postId = null,
        public readonly ?int $threadId = null,
        public readonly ?string $threadTitle = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $authorName = null,
        public readonly ?string $reason = null,
        public readonly ?string $reporterName = null,
        public readonly ?string $username = null,
        public readonly ?string $email = null,
    ) {
    }
}
