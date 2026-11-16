<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * A validated, permitted reply, ready to be written in one transaction. `token` is the form's idempotency key;
 * `lockBypass` says whether the subject may reply to a locked thread, which the writer re-checks under the
 * thread row lock. `network` and `networkSeconds` feed the client-network rate limit (M03.15).
 */
final class NewReply
{
    public function __construct(
        public readonly int $threadId,
        public readonly int $forumId,
        public readonly int $userId,
        public readonly string $token,
        public readonly string $content,
        public readonly string $contentFormat,
        public readonly string $renderedHtml,
        public readonly int $rendererVersion,
        public readonly PostStatus $status,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $floodSeconds,
        public readonly bool $lockBypass,
        public readonly ?string $network = null,
        public readonly int $networkSeconds = 0,
    ) {
    }
}
