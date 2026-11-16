<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Stores a permitted report atomically with its rate-limit hit and the `post.reported` outbox event (M04.07a).
 */
interface ReportWriter
{
    /**
     * @return int The report ID.
     *
     * @throws ThreadRuleViolation `report_exists` (an open report of this user on this post) or
     *     `report_flood_wait` (`seconds`).
     */
    public function create(
        int $postId,
        int $reporterId,
        string $reason,
        DateTimeImmutable $at,
        int $intervalSeconds,
    ): int;
}
