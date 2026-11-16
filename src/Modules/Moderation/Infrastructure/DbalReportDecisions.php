<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Moderation\Domain\ReportDecisions;
use Nolviko\Modules\Moderation\Domain\ResolveReport;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;

/**
 * ReportDecisions on DBAL. Closing clears `open_reporter_id`, so the reporter may report the post again later.
 */
final class DbalReportDecisions implements ReportDecisions
{
    private const STATUS = [ResolveReport::RESOLVE => 'resolved', ResolveReport::DISMISS => 'dismissed'];

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function find(int $reportId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT t.forum_id, r.status FROM reports r JOIN posts p ON p.id = r.post_id '
                . 'JOIN threads t ON t.id = p.thread_id WHERE r.id = ?',
            [$reportId],
        );

        return $row === false ? null : ['forum_id' => (int) $row['forum_id'], 'status' => (string) $row['status']];
    }

    public function close(int $reportId, string $action, int $actorId, string $note, DateTimeImmutable $at): void
    {
        $this->connection->transactional(function (Connection $connection) use (
            $reportId,
            $action,
            $actorId,
            $note,
            $at,
        ): void {
            $report = $connection->fetchAssociative(
                'SELECT post_id, status FROM reports WHERE id = ? FOR UPDATE',
                [$reportId],
            );

            if ($report === false || $report['status'] !== 'open') {
                throw new ThreadRuleViolation('report_not_open');
            }

            $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $status = self::STATUS[$action];
            $connection->update('reports', [
                'status' => $status,
                'open_reporter_id' => null,
                'resolved_by' => $actorId,
                'resolution_note' => $note,
                'resolved_at' => $stamp,
            ], ['id' => $reportId]);
            $this->audit->record(
                'report.' . $action,
                'report.' . $status,
                'report',
                $reportId,
                $actorId,
                $note,
                $stamp,
                ['post_id' => (int) $report['post_id']],
            );
        });
    }
}
