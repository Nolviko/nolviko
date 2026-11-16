<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Resolve or dismiss a report (M04.07). Needs `moderation.report.handle` in the reported post's forum and a
 * note of 1–500 characters; reports outside the moderator's forums are not found. The decision is audited.
 */
final class ResolveReport
{
    public const PERMISSION = 'moderation.report.handle';

    public const RESOLVE = 'resolve';

    public const DISMISS = 'dismiss';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly ReportDecisions $reports,
        private readonly ForumVisibility $visibility,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @throws ThreadRuleViolation `moderation_denied` (guests), `moderation_action_invalid`, `report_not_found`,
     *     `report_note_invalid` (`max`) or `report_not_open`.
     */
    public function decide(SubjectPermissions $subject, int $reportId, string $action, string $note): void
    {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');

        if ($action !== self::RESOLVE && $action !== self::DISMISS) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $report = $this->reports->find($reportId);

        if (
            $report === null
            || !$this->visibility->readable($subject)->contains($report['forum_id'])
            || !$subject->allows(self::PERMISSION, $report['forum_id'])
        ) {
            throw new ThreadRuleViolation('report_not_found');
        }

        $note = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', mb_check_encoding($note, 'UTF-8') ? $note : ''));

        if ($note === '' || mb_strlen($note) > ReportPost::MAX_REASON_LENGTH || preg_match('/\p{Cc}/u', $note) === 1) {
            throw new ThreadRuleViolation('report_note_invalid', ['max' => ReportPost::MAX_REASON_LENGTH]);
        }

        $this->reports->close($reportId, $action, $userId, $note, ($this->clock)());
    }
}
