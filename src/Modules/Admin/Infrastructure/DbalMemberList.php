<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\UserStatus;

/**
 * The admin member list (M04.06): newest accounts first in keyset pages (ADR-014), filtered by a name prefix and
 * a status, and the bulk suspend/reactivate action. Administrators and the acting admin are never changed in
 * bulk; every changed account gets its own audit entry and outbox event.
 */
final class DbalMemberList
{
    public const PAGE_SIZE = 50;

    public const MAX_BULK = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{id: int, username: string, email: string, status: string, created_at: string}> Up to
     *     PAGE_SIZE + 1 rows; the extra one only tells that an older page exists.
     */
    public function page(string $name, ?UserStatus $status, ?int $before): array
    {
        $conditions = [];
        $parameters = [];
        $types = [];
        $prefix = IdentityNormalizer::username($name);

        if ($prefix !== '') {
            $conditions[] = 'username_normalized LIKE ?';
            $parameters[] = addcslashes($prefix, '%_\\') . '%';
            $types[] = ParameterType::STRING;
        }

        if ($status !== null) {
            $conditions[] = 'status = ?';
            $parameters[] = $status->value;
            $types[] = ParameterType::STRING;
        }

        if ($before !== null) {
            $conditions[] = 'id < ?';
            $parameters[] = $before;
            $types[] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, username, email, status, created_at FROM users'
                . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
                . ' ORDER BY id DESC LIMIT ' . (self::PAGE_SIZE + 1),
            $parameters,
            $types,
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Moves the given accounts between `active` and `suspended`.
     *
     * @param list<int> $userIds
     *
     * @return int How many accounts changed (administrators, the actor and accounts already there are skipped).
     */
    public function setSuspended(array $userIds, bool $suspended, int $actorId, ?string $reason): int
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));

        if ($userIds === [] || count($userIds) > self::MAX_BULK) {
            return 0;
        }

        $from = $suspended ? UserStatus::Active : UserStatus::Suspended;
        $to = $suspended ? UserStatus::Suspended : UserStatus::Active;
        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $audit = new AuditTrail($this->connection, new Outbox($this->connection));

        return $this->connection->transactional(function (Connection $connection) use (
            $userIds,
            $from,
            $to,
            $actorId,
            $reason,
            $at,
            $audit,
            $suspended,
        ): int {
            $targets = $connection->fetchFirstColumn(
                'SELECT u.id FROM users u WHERE u.id IN (?) AND u.status = ? AND u.id <> ?'
                    . ' AND NOT EXISTS (SELECT 1 FROM usergroup_members m'
                    . ' WHERE m.user_id = u.id AND m.usergroup_id = ?)'
                    . ' ORDER BY u.id FOR UPDATE',
                [$userIds, $from->value, $actorId, BuiltInGroup::Administrators->value],
                [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            foreach ($targets as $id) {
                $connection->executeStatement(
                    'UPDATE users SET status = ?, session_epoch = session_epoch + 1, updated_at = ? WHERE id = ?',
                    [$to->value, $at, (int) $id],
                );
                $audit->record(
                    $suspended ? 'user.suspend' : 'user.reactivate',
                    $suspended ? 'user.suspended' : 'user.reactivated',
                    'user',
                    (int) $id,
                    $actorId,
                    $reason,
                    $at,
                    [],
                );
            }

            return count($targets);
        });
    }
}
