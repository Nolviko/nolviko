<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Groups\Domain\Usergroup;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;

/**
 * UsergroupRepository on DBAL with bound parameters. Membership changes that touch the primary flag run in a
 * transaction, so a user is never left with two primaries or (after a group delete) none.
 */
final class DbalUsergroupRepository implements UsergroupRepository
{
    private const COLUMNS = 'id, slug, name, built_in';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM usergroups ORDER BY id');

        return array_map(self::hydrate(...), $rows);
    }

    public function find(int $id): ?Usergroup
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM usergroups WHERE id = ?',
            [$id],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function findBySlug(string $slug): ?Usergroup
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM usergroups WHERE slug = ?',
            [$slug],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function create(string $slug, string $name): Usergroup
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new GroupRuleViolation('group_slug_invalid');
        }

        try {
            $this->connection->insert('usergroups', [
                'slug' => $slug,
                'name' => $name,
                'built_in' => 0,
                'created_at' => self::now(),
                'updated_at' => self::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new GroupRuleViolation('group_slug_taken');
        }

        return $this->find((int) $this->connection->lastInsertId())
            ?? throw new GroupRuleViolation('group_not_found');
    }

    public function delete(int $id): void
    {
        if (BuiltInGroup::isBuiltIn($id)) {
            throw new GroupRuleViolation('group_builtin_protected');
        }

        $this->connection->transactional(function (Connection $connection) use ($id): void {
            $orphans = $connection->fetchFirstColumn(
                'SELECT user_id FROM usergroup_members WHERE usergroup_id = ? AND is_primary = 1 FOR UPDATE',
                [$id],
            );

            if ($connection->delete('usergroups', ['id' => $id]) === 0) {
                throw new GroupRuleViolation('group_not_found');
            }

            foreach ($orphans as $userId) {
                $this->makePrimary($connection, (int) $userId, BuiltInGroup::Registered->value);
            }
        });
    }

    public function addMember(int $userId, int $groupId, bool $primary = false): void
    {
        if ($groupId === BuiltInGroup::Guests->value) {
            throw new GroupRuleViolation('group_guests_no_members');
        }

        $this->connection->transactional(function (Connection $connection) use ($userId, $groupId, $primary): void {
            $hasPrimary = $connection->fetchOne(
                'SELECT 1 FROM usergroup_members WHERE user_id = ? AND is_primary = 1 FOR UPDATE',
                [$userId],
            ) !== false;

            try {
                // Nested: a savepoint, so a duplicate does not abort the transaction (PostgreSQL).
                $connection->transactional(static fn(Connection $inner): int|string => $inner->insert(
                    'usergroup_members',
                    ['user_id' => $userId, 'usergroup_id' => $groupId, 'is_primary' => 0, 'created_at' => self::now()],
                ));
            } catch (UniqueConstraintViolationException) {
                // Already a member: adding is idempotent.
            } catch (ForeignKeyConstraintViolationException) {
                throw new GroupRuleViolation('group_not_found');
            }

            if ($primary || !$hasPrimary) {
                $this->makePrimary($connection, $userId, $groupId);
            }
        });
    }

    public function removeMember(int $userId, int $groupId): void
    {
        $isPrimary = $this->connection->fetchOne(
            'SELECT is_primary FROM usergroup_members WHERE user_id = ? AND usergroup_id = ?',
            [$userId, $groupId],
        );

        if ((int) $isPrimary === 1) {
            throw new GroupRuleViolation('group_primary_required');
        }

        $this->connection->delete('usergroup_members', ['user_id' => $userId, 'usergroup_id' => $groupId]);
    }

    public function setPrimary(int $userId, int $groupId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($userId, $groupId): void {
            $member = $connection->fetchOne(
                'SELECT 1 FROM usergroup_members WHERE user_id = ? AND usergroup_id = ? FOR UPDATE',
                [$userId, $groupId],
            );

            if ($member === false) {
                throw new GroupRuleViolation('group_not_member');
            }

            $this->makePrimary($connection, $userId, $groupId);
        });
    }

    public function groupIdsOf(int $userId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT usergroup_id FROM usergroup_members WHERE user_id = ? ORDER BY is_primary DESC, usergroup_id',
            [$userId],
        );

        return array_map(intval(...), $ids);
    }

    public function primaryGroupOf(int $userId): ?int
    {
        $id = $this->connection->fetchOne(
            'SELECT usergroup_id FROM usergroup_members WHERE user_id = ? AND is_primary = 1',
            [$userId],
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * Inside a transaction: clears the old primary first (the UNIQUE on primary_user_id would reject two), then
     * marks the membership primary, creating it if needed (portable SQL, no upsert).
     */
    private function makePrimary(Connection $connection, int $userId, int $groupId): void
    {
        $connection->executeStatement(
            'UPDATE usergroup_members SET is_primary = 0 WHERE user_id = ? AND is_primary = 1',
            [$userId],
        );
        $updated = $connection->update(
            'usergroup_members',
            ['is_primary' => 1],
            ['user_id' => $userId, 'usergroup_id' => $groupId],
        );

        if ($updated === 0) {
            $connection->insert('usergroup_members', [
                'user_id' => $userId,
                'usergroup_id' => $groupId,
                'is_primary' => 1,
                'created_at' => self::now(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Usergroup
    {
        return new Usergroup(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (bool) $row['built_in'],
        );
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
