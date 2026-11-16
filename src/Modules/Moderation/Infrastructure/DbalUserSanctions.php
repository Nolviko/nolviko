<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Moderation\Domain\UserSanctions;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;

/**
 * UserSanctions on DBAL. "Active" is always evaluated against the given time in the query (no scheduled
 * expiry), on `ix_user_bans_user` and `ix_warnings_user`.
 */
final class DbalUserSanctions implements UserSanctions
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const ACTIVE_BAN = 'user_id = ? AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > ?)';

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function activeBanUntil(int $userId, DateTimeImmutable $now): DateTimeImmutable|false|null
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT expires_at FROM user_bans WHERE ' . self::ACTIVE_BAN,
            [$userId, self::stamp($now)],
        );

        if ($rows === []) {
            return false;
        }

        if (in_array(null, $rows, true)) {
            return null;
        }

        return new DateTimeImmutable((string) max($rows), new DateTimeZone('UTC'));
    }

    public function ban(
        int $userId,
        int $actorId,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): int {
        return $this->connection->transactional(function (Connection $connection) use (
            $userId,
            $actorId,
            $reason,
            $expiresAt,
            $at,
        ): int {
            $connection->insert('user_bans', [
                'user_id' => $userId,
                'reason' => $reason,
                'created_by' => $actorId,
                'created_at' => self::stamp($at),
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
            ]);
            $banId = (int) $connection->lastInsertId();
            $this->audit->record('user.ban', 'user.banned', 'user', $userId, $actorId, $reason, self::stamp($at), [
                'ban_id' => $banId,
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
            ]);

            return $banId;
        });
    }

    public function lift(int $userId, int $actorId, ?string $reason, DateTimeImmutable $at): void
    {
        $this->connection->transactional(function (Connection $connection) use (
            $userId,
            $actorId,
            $reason,
            $at,
        ): void {
            $lifted = $connection->executeStatement(
                'UPDATE user_bans SET lifted_at = ?, lifted_by = ? WHERE ' . self::ACTIVE_BAN,
                [self::stamp($at), $actorId, $userId, self::stamp($at)],
            );

            if ($lifted === 0) {
                throw new ThreadRuleViolation('user_not_banned');
            }

            $this->audit->record(
                'user.unban',
                'user.unbanned',
                'user',
                $userId,
                $actorId,
                $reason,
                self::stamp($at),
                ['lifted' => $lifted],
            );
        });
    }

    public function warn(
        int $userId,
        int $actorId,
        int $points,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): int {
        return $this->connection->transactional(function (Connection $connection) use (
            $userId,
            $actorId,
            $points,
            $reason,
            $expiresAt,
            $at,
        ): int {
            $connection->insert('warnings', [
                'user_id' => $userId,
                'points' => $points,
                'reason' => $reason,
                'created_by' => $actorId,
                'created_at' => self::stamp($at),
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
            ]);
            $warningId = (int) $connection->lastInsertId();
            $this->audit->record('user.warn', 'user.warned', 'user', $userId, $actorId, $reason, self::stamp($at), [
                'warning_id' => $warningId,
                'points' => $points,
            ]);

            return $warningId;
        });
    }

    public function activePoints(int $userId, DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(points), 0) FROM warnings'
                . ' WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?)',
            [$userId, self::stamp($now)],
        );
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
