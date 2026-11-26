<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Plain-text profile editor and keyset directory; email and credentials are never projected. */
final class MemberProfiles
{
    public const DEFAULTS = [
        'biography' => '',
        'location' => '',
        'signature' => '',
        'visibility' => 'everyone',
        'allow_messages' => 1,
        'show_online' => 1,
        'email_notifications' => 0,
        'notify_replies' => 1,
        'notify_reactions' => 1,
        'notify_follows' => 1,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
    ) {
    }

    public function find(SubjectPermissions $subject, int $id): ?array
    {
        $user = $this->db->fetchAssociative(
            "SELECT id, username, created_at FROM users WHERE id = ? AND status = 'active'",
            [$id],
        );

        if ($user === false) {
            return null;
        }

        $row = $this->db->fetchAssociative('SELECT user_id, biography, location, '
            . 'COALESCE(imported_signature, signature) AS signature, visibility, '
            . 'allow_messages, show_online, '
            . 'email_notifications, notify_replies, notify_reactions, notify_follows '
            . 'FROM member_profiles WHERE user_id = ?', [$id]);
        $profile = ($row === false ? [] : $row) + self::DEFAULTS + ['user_id' => $id, 'username' => $user['username'],
            'joined_at' => $user['created_at']];

        return $this->access->profileVisible($subject, $profile) ? $profile : null;
    }

    public function save(SubjectPermissions $subject, array $fields): void
    {
        $actor = $this->access->actor($subject);
        $values = [];

        foreach (['biography' => 4000, 'location' => 120, 'signature' => 1000] as $key => $limit) {
            $values[$key] = CommunityAccess::text(is_string($fields[$key] ?? null) ? $fields[$key] : '', $limit);
        }

        if (!in_array($fields['visibility'] ?? '', ['everyone', 'members', 'private'], true)) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $values['visibility'] = $fields['visibility'];

        foreach (
            ['allow_messages', 'show_online', 'email_notifications',
            'notify_replies', 'notify_reactions', 'notify_follows'] as $key
        ) {
            $values[$key] = ($fields[$key] ?? '') === '1' ? 1 : 0;
        }

        $this->db->transactional(static function (Connection $db) use ($actor, $values): void {
            $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor]);
            $db->executeStatement('DELETE FROM member_profiles WHERE user_id = ?', [$actor]);
            $db->insert('member_profiles', ['user_id' => $actor] + $values);
        });
        // Custom profile fields (2026-11-08), from the same form.
        (new MemberStanding($this->db))->saveValues($actor, $fields);
    }

    /**
     * Roadmap 24 (beta.18): `$name` keeps usernames that start with it; `$sort` `name` or `joined` (newest first, index
     * ix_users_directory_joined) pages by `$offset`, the default member-number order keeps its keyset.
     */
    public function directory(
        SubjectPermissions $subject,
        int $after,
        bool $online,
        string $name = '',
        string $sort = '',
        int $offset = 0,
    ): array {
        if (!$subject->allows('user.directory.view') || !$subject->allows('user.profile.view')) {
            throw new RegistrationRejected(['community_denied']);
        }

        $actor = $subject->userId ?? 0;
        $order = ['name' => 'u.username, u.id', 'joined' => 'u.created_at DESC, u.id DESC'][$sort] ?? null;
        $name = trim($name);
        $filter = $name === '' ? '' : 'AND LOWER(u.username) LIKE ? ';
        $like = mb_strtolower(addcslashes(mb_substr($name, 0, 50), '%_\\')) . '%';

        return $this->db->fetchAllAssociative(
            'SELECT u.id, u.username, u.created_at AS joined_at FROM users u '
                . 'LEFT JOIN member_profiles p ON p.user_id = u.id '
                . ($online ? 'INNER JOIN member_activity a ON a.user_id = u.id ' : '')
                . "WHERE u.status = 'active' AND u.id > ? " . $filter
                . "AND (p.user_id IS NULL OR p.visibility = 'everyone' "
                . "OR (p.visibility = 'members' AND ? > 0)) "
                . 'AND NOT EXISTS (SELECT 1 FROM member_blocks b WHERE '
                . '(b.user_id = ? AND b.target_id = u.id) OR (b.target_id = ? AND b.user_id = u.id)) '
                . ($online ? 'AND COALESCE(p.show_online, 1) = 1 '
                    . "AND a.last_seen_at >= utc_timestamp(6) - INTERVAL '15' MINUTE " : '')
                . 'ORDER BY ' . ($order ?? 'u.id') . ' LIMIT 26' . ($order !== null && $offset > 0
                    ? ' OFFSET ' . max(0, min($offset, 2600)) : ''),
            [$order === null ? $after : 0, ...($filter === '' ? [] : [$like]), $actor, $actor, $actor],
        );
    }

    public function touch(SubjectPermissions $subject): void
    {
        if ($subject->userId === null) {
            return;
        }

        $this->db->executeStatement(
            SqlDialect::upsert(
                $this->db,
                'INSERT INTO member_activity (user_id, last_seen_at) VALUES (?, utc_timestamp(6))',
                'member_activity',
                ['user_id'],
                "last_seen_at = CASE WHEN @old.last_seen_at < utc_timestamp(6) - INTERVAL '5' MINUTE "
                    . 'THEN @new.last_seen_at ELSE @old.last_seen_at END',
            ),
            [$subject->userId],
        );
    }

    public function blocks(int $actor): array
    {
        return $this->db->fetchAllAssociative('SELECT b.target_id, u.username FROM member_blocks b '
            . 'INNER JOIN users u ON u.id = b.target_id WHERE b.user_id = ? ORDER BY b.target_id LIMIT 201', [$actor]);
    }

    public function contactAllowed(int $sender, int $recipient): bool
    {
        if ($sender === $recipient) {
            return false;
        }

        return $this->db->fetchOne(
            "SELECT 1 FROM users u LEFT JOIN member_profiles p ON p.user_id = u.id WHERE u.id = ? "
                . "AND u.status = 'active' AND COALESCE(p.allow_messages, 1) = 1 "
                . 'AND NOT EXISTS (SELECT 1 FROM member_blocks b WHERE (b.user_id = ? AND b.target_id = u.id) '
                . 'OR (b.user_id = u.id AND b.target_id = ?))',
            [$recipient, $sender, $sender],
        ) !== false;
    }
}
