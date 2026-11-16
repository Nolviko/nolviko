<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Reading and closing reports (M04.07).
 */
interface ReportDecisions
{
    /**
     * @return array{forum_id: int, status: string}|null The report's forum (through its post) and status.
     */
    public function find(int $reportId): ?array;

    /**
     * Closes an open report under its row lock, with the audit entry and outbox event.
     *
     * @param string $action ResolveReport::RESOLVE or ResolveReport::DISMISS.
     *
     * @throws ThreadRuleViolation `report_not_open`.
     */
    public function close(int $reportId, string $action, int $actorId, string $note, DateTimeImmutable $at): void;
}
