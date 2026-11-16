<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\Usergroup;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionRuleStore;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use Nolviko\Modules\Permissions\Domain\Precedence;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;

/**
 * Group and group-rule administration (M04.14). Every change needs a fresh administrator session, runs in one
 * transaction with an `audit_logs` row and (for anything that changes effective rules) a permission-rules version
 * bump. Rules follow ADR-011: no row = inherit; bool keys take allow / deny / never; int and enum keys take a value.
 *
 * Lock-out guards: an administrator cannot remove their own Administrators membership, the Administrators group's
 * `admin.access` rule cannot be edited here, and the Guests group can never be granted `admin.access`.
 */
final readonly class GroupAdministration
{
    public const NAME_MAX = 100;

    public const MEMBERS_PAGE = 50;

    /** Form value meaning "no rule": the group inherits. */
    public const INHERIT = '';

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public function __construct(
        private Connection $db,
        private UsergroupRepository $groups,
        private PermissionRuleStore $rules,
        private PermissionCatalog $catalog,
    ) {
    }

    /** @return list<array{id: int, slug: string, name: string, built_in: bool, members: int}> */
    public function overview(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT g.id, g.slug, g.name, g.built_in, COUNT(m.user_id) AS members FROM usergroups g '
                . 'LEFT JOIN usergroup_members m ON m.usergroup_id = g.id '
                . 'GROUP BY g.id, g.slug, g.name, g.built_in ORDER BY g.id',
        );

        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'slug' => (string) $row['slug'],
                'name' => (string) $row['name'], 'built_in' => (bool) $row['built_in'],
                'members' => (int) $row['members']],
            $rows,
        );
    }

    public function find(int $id): ?Usergroup
    {
        return $this->groups->find($id);
    }

    public function create(SubjectPermissions $subject, bool $fresh, string $name, string $slug): Usergroup
    {
        $actor = self::actor($subject, $fresh);
        $name = self::name($name);

        return $this->db->transactional(function () use ($actor, $name, $slug): Usergroup {
            $group = $this->groups->create(trim($slug), $name);
            $this->audit($actor, $group->id, 'admin.group_created', ['slug' => $group->slug, 'name' => $name]);

            return $group;
        });
    }

    public function rename(SubjectPermissions $subject, bool $fresh, int $id, string $name): void
    {
        $actor = self::actor($subject, $fresh);
        $name = self::name($name);
        $group = $this->custom($id);

        $this->db->transactional(function () use ($actor, $group, $name): void {
            $this->db->update('usergroups', ['name' => $name, 'updated_at' => $this->now()], ['id' => $group->id]);
            $this->audit($actor, $group->id, 'admin.group_renamed', ['from' => $group->name, 'to' => $name]);
        });
    }

    public function delete(SubjectPermissions $subject, bool $fresh, int $id): void
    {
        $actor = self::actor($subject, $fresh);
        $group = $this->custom($id);

        $this->db->transactional(function () use ($actor, $group): void {
            $this->groups->delete($group->id);
            $this->audit($actor, $group->id, 'admin.group_deleted', ['slug' => $group->slug, 'name' => $group->name]);
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
        });
    }

    /**
     * Members by user ID, keyset paged; one extra row tells the caller that an older page exists.
     *
     * @return list<array{id: int, username: string, primary: bool}>
     */
    public function members(int $id, int $after = 0): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.username, m.is_primary FROM usergroup_members m INNER JOIN users u ON u.id = m.user_id '
                . 'WHERE m.usergroup_id = ? AND u.id > ? ORDER BY u.id LIMIT ' . (self::MEMBERS_PAGE + 1),
            [$id, $after],
        );

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'],
            'username' => (string) $row['username'], 'primary' => (bool) $row['is_primary']], $rows);
    }

    /** @throws InvalidArgumentException `group_member_unknown` when no account has that username. */
    public function addMember(SubjectPermissions $subject, bool $fresh, int $id, string $username): void
    {
        $actor = self::actor($subject, $fresh);
        $group = $this->groups->find($id) ?? throw new InvalidArgumentException('group_not_found');
        $user = mb_strlen($username) <= 64 ? $this->db->fetchAssociative(
            'SELECT id, username FROM users WHERE username_normalized = ?',
            [IdentityNormalizer::username(trim($username))],
        ) : false;

        if ($user === false) {
            throw new InvalidArgumentException('group_member_unknown');
        }

        $this->db->transactional(function () use ($actor, $group, $user): void {
            $this->groups->addMember((int) $user['id'], $group->id);
            $this->audit($actor, $group->id, 'admin.group_member_added', ['user' => (int) $user['id'],
                'username' => (string) $user['username']]);
        });
    }

    public function removeMember(SubjectPermissions $subject, bool $fresh, int $id, int $userId): void
    {
        $actor = self::actor($subject, $fresh);
        $group = $this->groups->find($id) ?? throw new InvalidArgumentException('group_not_found');

        if ($group->id === BuiltInGroup::Administrators->value && $userId === $actor) {
            throw new InvalidArgumentException('group_self_lockout');
        }

        $this->db->transactional(function () use ($actor, $group, $userId): void {
            $this->groups->removeMember($userId, $group->id);
            $this->audit($actor, $group->id, 'admin.group_member_removed', ['user' => $userId]);
        });
    }

    /** Makes one of the member's groups their primary group (decides how they are shown). */
    public function setPrimary(SubjectPermissions $subject, bool $fresh, int $id, int $userId): void
    {
        $actor = self::actor($subject, $fresh);
        $group = $this->groups->find($id) ?? throw new InvalidArgumentException('group_not_found');

        $this->db->transactional(function () use ($actor, $group, $userId): void {
            $this->groups->setPrimary($userId, $group->id);
            $this->audit($actor, $group->id, 'admin.group_primary_set', ['user' => $userId]);
        });
    }

    /**
     * The group's own rules at one scope, as form values: '' (inherit), allow, deny, never, or the int/enum value.
     *
     * @return array<string, string> Keyed by permission key; keys without a rule are absent.
     */
    public function rules(int $id, ?int $forum): array
    {
        $rows = $forum === null
            ? $this->db->fetchAllKeyValue(
                'SELECT permission_key, value FROM usergroup_permissions WHERE usergroup_id = ?',
                [$id],
            )
            : $this->db->fetchAllKeyValue(
                'SELECT permission_key, value FROM forum_permissions WHERE usergroup_id = ? AND forum_id = ?',
                [$id, $forum],
            );
        $values = [];

        foreach ($rows as $key => $stored) {
            $definition = $this->catalog->find((string) $key);

            if ($definition !== null) {
                $values[(string) $key] = $definition->type === PermissionType::Bool
                    ? match ((string) $stored) {
                        Precedence::NEVER => Precedence::NEVER,
                        '1' => self::ALLOW,
                        default => self::DENY,
                    }
                    : (string) $stored;
            }
        }

        return $values;
    }

    /** Whether the editor must show this key read-only for this group (lock-out guards). */
    public static function locked(int $groupId, string $key): bool
    {
        return $key === 'admin.access' && in_array(
            $groupId,
            [BuiltInGroup::Administrators->value, BuiltInGroup::Guests->value],
            true,
        );
    }

    /**
     * Saves the submitted form values; only keys whose value changed are written (one audit row lists them).
     *
     * @param array<string, string> $submitted Permission key => form value.
     * @return int Number of rules changed.
     * @throws InvalidArgumentException `group_rule_invalid` on an unknown key or a value outside its definition.
     */
    public function saveRules(
        SubjectPermissions $subject,
        bool $fresh,
        int $id,
        ?int $forum,
        array $submitted,
    ): int {
        $actor = self::actor($subject, $fresh);
        $group = $this->groups->find($id) ?? throw new InvalidArgumentException('group_not_found');
        $current = $this->rules($group->id, $forum);
        $changes = [];

        foreach ($submitted as $key => $raw) {
            $definition = $this->catalog->find((string) $key)
                ?? throw new InvalidArgumentException('group_rule_invalid');
            $raw = trim((string) $raw);

            if ($raw === ($current[$key] ?? self::INHERIT) || self::locked($group->id, (string) $key)) {
                continue;
            }

            $value = match (true) {
                $raw === self::INHERIT => null,
                $definition->type === PermissionType::Bool => match ($raw) {
                    self::ALLOW => true,
                    self::DENY => false,
                    Precedence::NEVER => Precedence::NEVER,
                    default => throw new InvalidArgumentException('group_rule_invalid'),
                },
                $definition->type === PermissionType::Int => preg_match('/^[0-9]{1,9}$/D', $raw) === 1
                    ? (int) $raw : throw new InvalidArgumentException('group_rule_invalid'),
                default => $raw,
            };

            try {
                if ($value !== null) {
                    $definition->encodeRule($value);
                }
            } catch (PermissionError) {
                throw new InvalidArgumentException('group_rule_invalid');
            }

            $changes[(string) $key] = $value;
        }

        if ($changes === []) {
            return 0;
        }

        $this->db->transactional(function () use ($actor, $group, $forum, $changes): void {
            foreach ($changes as $key => $value) {
                $this->rules->setGroupRule($group->id, $key, $value, $forum);
            }

            $this->audit($actor, $group->id, 'admin.group_rules_changed', ['forum' => $forum, 'changes' => $changes]);
        });

        return count($changes);
    }

    private function custom(int $id): Usergroup
    {
        $group = $this->groups->find($id) ?? throw new InvalidArgumentException('group_not_found');

        if ($group->builtIn) {
            throw new InvalidArgumentException('group_builtin_protected');
        }

        return $group;
    }

    private static function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('group_name_invalid');
        }

        return $name;
    }

    private static function actor(SubjectPermissions $subject, bool $fresh): int
    {
        if (!$fresh || $subject->userId === null || !$subject->allows('admin.access')) {
            throw new PermissionError('group_admin_denied');
        }

        return $subject->userId;
    }

    /** @param array<string, mixed> $details */
    private function audit(int $actor, int $group, string $action, array $details): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actor, 'action' => $action, 'target_type' => 'usergroup',
            'target_id' => $group, 'reason' => null, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $this->now()]);
    }

    private function now(): string
    {
        return (string) $this->db->fetchOne('SELECT UTC_TIMESTAMP(6)');
    }
}
