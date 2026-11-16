<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;

/** Audited, fresh-admin assignment changes; permission projection remains governed by ADR-011. */
final readonly class ForumModeratorAssignments
{
    public function __construct(private Connection $db)
    {
    }

    public function assign(SubjectPermissions $subject, bool $fresh, int $forum, string $member, ?int $group): bool
    {
        $actor = self::actor($subject, $fresh);
        if (
            !mb_check_encoding($member, 'UTF-8') || strlen($member) > 256
            || grapheme_strlen($member) > 64 || preg_match('/[\x00-\x1f\x7f]/', $member) === 1
        ) {
            throw new InvalidArgumentException('forum_moderator_target_invalid');
        }
        if (($member === '') === ($group === null) || $group === BuiltInGroup::Guests->value) {
            throw new InvalidArgumentException('forum_moderator_target_invalid');
        }

        $user = $member === '' ? null : $this->db->fetchOne(
            "SELECT id FROM users WHERE username_normalized = ? AND status = 'active'",
            [IdentityNormalizer::username($member)],
        );

        if (
            ($member !== '' && $user === false)
            || ($group !== null && $this->db->fetchOne('SELECT id FROM usergroups WHERE id = ?', [$group]) === false)
        ) {
            throw new InvalidArgumentException('forum_moderator_target_invalid');
        }

        return $this->db->transactional(function () use ($actor, $forum, $user, $group): bool {
            if ($this->db->fetchOne('SELECT id FROM forums WHERE id = ? FOR UPDATE', [$forum]) === false) {
                throw new InvalidArgumentException('forum_not_found');
            }

            $target = $group === null ? ['user_id' => (int) $user] : ['usergroup_id' => $group];
            $column = $group === null ? 'user_id' : 'usergroup_id';
            if (
                $this->db->fetchOne(
                    'SELECT id FROM forum_moderators WHERE forum_id = ? AND ' . $column . ' = ?',
                    [$forum, current($target)]
                ) !== false
            ) {
                return false;
            }
            if (
                (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM forum_moderators WHERE forum_id = ?',
                    [$forum],
                ) >= 100
            ) {
                throw new InvalidArgumentException('forum_moderator_limit');
            }
            $this->db->insert('forum_moderators', ['forum_id' => $forum] + $target + ['created_at' => $this->now()]);
            $this->audit($actor, $forum, 'admin.forum_moderator_added', $target);
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);

            return true;
        });
    }

    public function remove(SubjectPermissions $subject, bool $fresh, int $id): bool
    {
        $actor = self::actor($subject, $fresh);

        return $this->db->transactional(function () use ($actor, $id): bool {
            $row = $this->db->fetchAssociative(
                'SELECT id, forum_id, user_id, usergroup_id FROM forum_moderators WHERE id = ? FOR UPDATE',
                [$id],
            );
            if ($row === false) {
                return false;
            }
            $this->db->delete('forum_moderators', ['id' => $id]);
            $this->audit(
                $actor,
                (int) $row['forum_id'],
                'admin.forum_moderator_removed',
                ['user_id' => $row['user_id'], 'usergroup_id' => $row['usergroup_id']]
            );
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);

            return true;
        });
    }

    /** @return list<array<string, mixed>> */
    public function page(SubjectPermissions $subject, ?int $forum = null, ?int $before = null): array
    {
        self::actor($subject, true);
        $where = ['1 = 1'];
        $parameters = [];
        if ($forum !== null) {
            $where[] = 'm.forum_id = ?';
            $parameters[] = $forum;
        }
        if ($before !== null) {
            $where[] = 'm.id < ?';
            $parameters[] = $before;
        }

        return $this->db->fetchAllAssociative(
            'SELECT m.id, m.forum_id, f.title AS forum_title, u.username, g.name AS group_name '
                . 'FROM forum_moderators m INNER JOIN forums f ON f.id = m.forum_id '
                . 'LEFT JOIN users u ON u.id = m.user_id LEFT JOIN usergroups g ON g.id = m.usergroup_id '
                . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 51',
            $parameters,
        );
    }

    private static function actor(SubjectPermissions $subject, bool $fresh): int
    {
        if (!$fresh || $subject->userId === null || !$subject->allows('admin.access')) {
            throw new PermissionError('forum_moderator_denied');
        }

        return $subject->userId;
    }

    /** @param array<string, mixed> $target */
    private function audit(int $actor, int $forum, string $action, array $target): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actor, 'action' => $action, 'target_type' => 'forum',
            'target_id' => $forum, 'reason' => null, 'details' => json_encode($target, JSON_THROW_ON_ERROR),
            'created_at' => $this->now()]);
    }

    private function now(): string
    {
        return (string) $this->db->fetchOne('SELECT UTC_TIMESTAMP(6)');
    }
}
