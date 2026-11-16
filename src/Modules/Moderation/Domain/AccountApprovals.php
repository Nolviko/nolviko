<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Decisions on accounts waiting for approval (M04.08), each in one statement with its audit entry.
 */
interface AccountApprovals
{
    /**
     * Pending → active.
     *
     * @throws ThreadRuleViolation `user_not_pending`.
     */
    public function approve(int $userId, int $actorId, ?string $reason): void;

    /**
     * Deletes the pending account (it has no content yet); the audit entry keeps its ID.
     *
     * @throws ThreadRuleViolation `user_not_pending`.
     */
    public function reject(int $userId, int $actorId, ?string $reason): void;
}
