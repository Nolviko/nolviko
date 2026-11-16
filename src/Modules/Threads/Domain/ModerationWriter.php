<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * Applies a permitted delete, restore or purge atomically (M03.07): the status change or hard delete, exact
 * thread and forum counters, the audit entry and the outbox event. `$action` is one of the ModerateContent
 * constants. The current state is re-checked under the row locks.
 */
interface ModerationWriter
{
    /**
     * @throws ThreadRuleViolation `post_not_found`, `content_already_deleted`, `content_not_deleted` or
     *     `content_not_pending`.
     */
    public function post(string $action, int $postId, int $actorId, ?string $reason, DateTimeImmutable $at): void;

    /**
     * @throws ThreadRuleViolation `thread_not_found`, `content_already_deleted`, `content_not_deleted` or
     *     `content_not_pending`.
     */
    public function thread(string $action, int $threadId, int $actorId, ?string $reason, DateTimeImmutable $at): void;
}
