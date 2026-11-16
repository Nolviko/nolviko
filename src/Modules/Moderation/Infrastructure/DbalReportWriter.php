<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Moderation\Domain\ReportWriter;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * ReportWriter on DBAL: the user's report bucket is created before the transaction and hit inside it, together
 * with the report row and the outbox event, so a duplicate report (UNIQUE on the open report) rolls the hit back.
 */
final class DbalReportWriter implements ReportWriter
{
    public const BUCKET = 'report.user:';

    public const EVENT = 'post.reported';

    private readonly RateBuckets $buckets;

    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
    ) {
        $this->buckets = new RateBuckets($connection);
    }

    public function create(
        int $postId,
        int $reporterId,
        string $reason,
        DateTimeImmutable $at,
        int $intervalSeconds,
    ): int {
        $bucket = self::BUCKET . $reporterId;
        $this->buckets->ensure($bucket, $at);

        try {
            return $this->connection->transactional(function (Connection $connection) use (
                $postId,
                $reporterId,
                $reason,
                $at,
                $intervalSeconds,
                $bucket,
            ): int {
                $wait = $this->buckets->hit($bucket, $intervalSeconds, $at);

                if ($wait > 0) {
                    throw new ThreadRuleViolation('report_flood_wait', ['seconds' => $wait]);
                }

                $connection->insert('reports', [
                    'post_id' => $postId,
                    'reporter_id' => $reporterId,
                    'open_reporter_id' => $reporterId,
                    'reason' => $reason,
                    'status' => 'open',
                    'created_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ]);
                $reportId = (int) $connection->lastInsertId();
                $this->outbox->record(self::EVENT, 'report', $reportId, [
                    'report_id' => $reportId,
                    'post_id' => $postId,
                ]);

                return $reportId;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ThreadRuleViolation('report_exists');
        }
    }
}
