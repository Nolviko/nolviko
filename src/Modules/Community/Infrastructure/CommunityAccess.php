<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Shared current-state checks; no administrator bypass for private profile details. */
final class CommunityAccess
{
    public function __construct(
        private readonly Connection $db,
        private readonly ForumVisibility $visibility,
    ) {
    }

    public function actor(SubjectPermissions $subject): int
    {
        if (
            $subject->userId === null || $this->db->fetchOne(
                "SELECT id FROM users WHERE id = ? AND status = 'active'",
                [$subject->userId],
            ) === false
        ) {
            throw new RegistrationRejected(['community_denied']);
        }

        return $subject->userId;
    }

    public function thread(SubjectPermissions $subject, int $id, bool $write = false): array
    {
        $row = $this->db->fetchAssociative('SELECT id, user_id, forum_id, title, status, deleted_at, '
            . 'moved_to_thread_id, is_locked, '
            . 'first_post_id FROM threads WHERE id = ?' . ($write ? ' FOR UPDATE' : ''), [$id]);

        if (
            $row === false || !$this->visibility->readable($subject)->contains((int) $row['forum_id'])
            || $row['status'] !== 'visible' || $row['deleted_at'] !== null || $row['moved_to_thread_id'] !== null
        ) {
            throw new RegistrationRejected(['community_not_found']);
        }

        return $row;
    }

    public function post(SubjectPermissions $subject, int $id, bool $write = false): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, user_id, thread_id, status, deleted_at, created_at FROM '
                . 'posts WHERE id = ?' . ($write ? ' FOR UPDATE' : ''),
            [$id]
        );

        if ($row === false || $row['status'] !== 'visible' || $row['deleted_at'] !== null) {
            throw new RegistrationRejected(['community_not_found']);
        }

        $this->thread($subject, (int) $row['thread_id'], $write);

        return $row;
    }

    public function blocked(int $first, int $second): bool
    {
        return $this->db->fetchOne(
            'SELECT user_id FROM member_blocks WHERE (user_id = ? AND target_id = ?) '
                . 'OR (user_id = ? AND target_id = ?) LIMIT 1',
            [$first, $second, $second, $first],
        ) !== false;
    }

    public function profileVisible(SubjectPermissions $subject, array $profile): bool
    {
        return $subject->allows('user.profile.view') && ($subject->userId === (int) $profile['user_id']
            || ($profile['visibility'] === 'everyone'
                || ($profile['visibility'] === 'members' && $subject->userId !== null))
                && ($subject->userId === null || !$this->blocked($subject->userId, (int) $profile['user_id'])));
    }

    public function scope(SubjectPermissions $subject, string $column): array
    {
        return ScopeSql::forums($this->visibility->readable($subject), $column);
    }

    public static function text(string $value, int $limit): string
    {
        if (
            strlen($value) > $limit * 4 || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1
        ) {
            throw new RegistrationRejected(['community_invalid']);
        }

        return trim($value);
    }
}
