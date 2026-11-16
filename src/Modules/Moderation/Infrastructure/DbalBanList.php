<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Moderation\Domain\BanEntry;
use Nolviko\Modules\Moderation\Domain\BanList;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;

/**
 * BanList on DBAL. A lookup is one statement: the address part compares packed bytes of equal length on
 * `ix_bans_ip`, the email part matches the lowercased address and its domain on `ix_bans_email`.
 */
final class DbalBanList implements BanList
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const ACTIVE = 'lifted_at IS NULL AND (expires_at IS NULL OR expires_at > ?)';

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly Connection $connection,
        Outbox $outbox,
    ) {
        $this->audit = new AuditTrail($connection, $outbox);
    }

    public function blocks(?string $ip, ?string $email, DateTimeImmutable $now): bool
    {
        $conditions = [];
        $parameters = [self::stamp($now)];
        $types = [ParameterType::STRING];
        $packed = BanEntry::pack($ip);

        if ($packed !== null) {
            $conditions[] = '(ip_start <= ? AND ip_end >= ? AND LENGTH(ip_start) = ?)';
            array_push($parameters, $packed, $packed, strlen($packed));
            array_push($types, ParameterType::BINARY, ParameterType::BINARY, ParameterType::INTEGER);
        }

        $email = $email === null ? '' : mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at !== false) {
            $conditions[] = "(ban_type = 'email' AND email_pattern = ?)";
            $conditions[] = "(ban_type = 'email_domain' AND email_pattern = ?)";
            array_push($parameters, $email, substr($email, $at + 1));
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }

        if ($conditions === []) {
            return false;
        }

        return $this->connection->fetchOne(
            'SELECT 1 FROM bans WHERE ' . self::ACTIVE . ' AND (' . implode(' OR ', $conditions) . ') LIMIT 1',
            $parameters,
            $types,
        ) !== false;
    }

    public function blocksUser(int $userId, ?string $ip, DateTimeImmutable $now): bool
    {
        $email = $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [$userId]);

        return $this->blocks($ip, is_string($email) ? $email : null, $now);
    }

    public function add(
        BanEntry $entry,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        int $actorId,
        DateTimeImmutable $at,
    ): int {
        return $this->connection->transactional(function (Connection $connection) use (
            $entry,
            $reason,
            $expiresAt,
            $actorId,
            $at,
        ): int {
            $connection->insert('bans', [
                'ban_type' => $entry->type,
                'ip_start' => $entry->ipStart,
                'ip_end' => $entry->ipEnd,
                'email_pattern' => $entry->emailPattern,
                'reason' => $reason,
                'created_by' => $actorId,
                'created_at' => self::stamp($at),
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
            ], [
                'ip_start' => ParameterType::BINARY,
                'ip_end' => ParameterType::BINARY,
            ]);
            $banId = (int) $connection->lastInsertId();
            $this->audit->record('ban.add', 'ban.added', 'ban', $banId, $actorId, $reason, self::stamp($at), [
                'type' => $entry->type,
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
            ]);

            return $banId;
        });
    }

    public function lift(int $banId, int $actorId, DateTimeImmutable $at): void
    {
        $this->connection->transactional(function (Connection $connection) use ($banId, $actorId, $at): void {
            $lifted = $connection->executeStatement(
                'UPDATE bans SET lifted_at = ?, lifted_by = ? WHERE id = ? AND ' . self::ACTIVE,
                [self::stamp($at), $actorId, $banId, self::stamp($at)],
            );

            if ($lifted === 0) {
                throw new ThreadRuleViolation('ban_not_found');
            }

            $this->audit->record('ban.lift', 'ban.lifted', 'ban', $banId, $actorId, null, self::stamp($at), []);
        });
    }

    public function active(DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, ban_type, ip_start, ip_end, email_pattern, reason, expires_at FROM bans'
                . ' WHERE ' . self::ACTIVE . ' ORDER BY id DESC LIMIT ' . max(1, $limit),
            [self::stamp($now)],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'entry' => BanEntry::stored(
                (string) $row['ban_type'],
                $row['ip_start'] === null ? null : (string) $row['ip_start'],
                $row['ip_end'] === null ? null : (string) $row['ip_end'],
                $row['email_pattern'] === null ? null : (string) $row['email_pattern'],
            ),
            'reason' => (string) $row['reason'],
            'expires_at' => $row['expires_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC')),
        ], $rows);
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
