<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * Unsent post texts (M03.14), one per member and place, so a closed tab or a failed submit loses nothing. A draft
 * belongs to its member only; it is shown again when the same form opens and removed once the post is sent.
 * Places are named by `threadContext()` (a new thread in a forum) and `replyContext()` (a reply in a thread).
 */
interface Drafts
{
    /** The longest draft kept, the same as the longest post. */
    public const MAX_CONTENT = CreateThread::MAX_CONTENT_LENGTH;

    public function save(int $userId, string $context, ?string $title, string $content, DateTimeImmutable $at): void;

    /**
     * @return array{title: ?string, content: string}|null
     */
    public function find(int $userId, string $context): ?array;

    public function discard(int $userId, string $context): void;
}
