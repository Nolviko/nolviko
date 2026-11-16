<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Moderation\Domain\AccountApprovals;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;

/**
 * AccountApprovals on DBAL: the status condition is part of the UPDATE or DELETE, so a decision applies only
 * to an account that is still pending, and two moderators cannot both decide.
 */
final class DbalAccountApprovals implements AccountApprovals
{
    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function approve(int $userId, int $actorId, ?string $reason): void
    {
        $this->decide($userId, $actorId, $reason, 'approve', 'approved', static fn(Connection $connection): int
            => $connection->executeStatement(
                "UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending_approval'",
                [$userId],
            ));
    }

    public function reject(int $userId, int $actorId, ?string $reason): void
    {
        $this->decide($userId, $actorId, $reason, 'reject', 'rejected', static fn(Connection $connection): int
            => $connection->executeStatement(
                "DELETE FROM users WHERE id = ? AND status = 'pending_approval'",
                [$userId],
            ));
    }

    /**
     * @param Closure(Connection): int $change Returns the number of affected rows.
     */
    private function decide(
        int $userId,
        int $actorId,
        ?string $reason,
        string $action,
        string $past,
        Closure $change,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $userId,
            $actorId,
            $reason,
            $action,
            $past,
            $change,
        ): void {
            if ($change($connection) !== 1) {
                throw new ThreadRuleViolation('user_not_pending');
            }

            $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $this->audit->record('user.' . $action, 'user.' . $past, 'user', $userId, $actorId, $reason, $at, []);
        });
    }
}
