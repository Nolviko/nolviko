<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Mail\MailMessage;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * Group features of the beta.18 roadmap (G3): join applications decided by group leaders or administrators (03),
 * group mail sent in small background batches (04), timed memberships that end by themselves (41, ADR-039) and
 * automatic promotion rules checked in background batches (42). Built-in groups (guests, members, moderators,
 * administrators) never take part: they cannot be joined, granted for a time or reached by a promotion rule, so
 * no member gains staff rights through these paths.
 */
final class GroupMembership
{
    public const MAIL_BATCH = 40;

    public const PROMOTION_BATCH = 200;

    public const EXPIRY_BATCH = 100;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Custom groups with their join settings and leader names.
     *
     * @return list<array{id: int, name: string, joinable: bool, description: string, leaders: list<array{id: int,
     *     username: string}>, members: int}>
     */
    public function groups(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT g.id, g.name, COALESCE(s.joinable, 0) AS joinable, '
            . "COALESCE(s.description, '') AS description, (SELECT COUNT(*) FROM usergroup_members m "
            . 'WHERE m.usergroup_id = g.id) AS members FROM usergroups g '
            . 'LEFT JOIN usergroup_settings s ON s.usergroup_id = g.id WHERE g.built_in = 0 ORDER BY g.name, g.id '
            . 'LIMIT 200');
        $leaders = [];

        foreach (
            $this->db->fetchAllAssociative('SELECT l.usergroup_id, u.id, u.username FROM usergroup_leaders l '
                . 'INNER JOIN users u ON u.id = l.user_id ORDER BY u.username LIMIT 2000') as $leader
        ) {
            $leaders[(int) $leader['usergroup_id']][] = ['id' => (int) $leader['id'],
                'username' => (string) $leader['username']];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'],
            'joinable' => (bool) $row['joinable'], 'description' => (string) $row['description'],
            'leaders' => $leaders[(int) $row['id']] ?? [], 'members' => (int) $row['members']], $rows);
    }

    /** @return list<array{id: int, name: string}> Every group that can receive group mail (not guests). */
    public function mailGroups(): array
    {
        $groups = [];

        $rows = $this->db->fetchAllAssociative('SELECT id, name FROM usergroups WHERE id <> 1 ORDER BY name, id');

        foreach ($rows as $row) {
            $groups[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $groups;
    }

    /** @throws RegistrationRejected `group_invalid` for a built-in or missing group. */
    public function saveSettings(int $groupId, bool $joinable, string $description): void
    {
        $this->custom($groupId);
        $description = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $description) ?? ''), 0, 300);
        $this->db->transactional(static function (Connection $db) use ($groupId, $joinable, $description): void {
            $db->delete('usergroup_settings', ['usergroup_id' => $groupId]);
            $db->insert('usergroup_settings', ['usergroup_id' => $groupId, 'joinable' => $joinable ? 1 : 0,
                'description' => $description, 'updated_at' => self::now()]);
        });
    }

    /** @throws RegistrationRejected `group_invalid` or `group_user_unknown`. */
    public function addLeader(int $groupId, string $username): void
    {
        $this->custom($groupId);
        $userId = $this->userId($username);

        try {
            $this->db->insert('usergroup_leaders', ['usergroup_id' => $groupId, 'user_id' => $userId,
                'created_at' => self::now()]);
        } catch (Throwable) {
            // Already a leader.
        }
    }

    public function removeLeader(int $groupId, int $userId): void
    {
        $this->db->delete('usergroup_leaders', ['usergroup_id' => $groupId, 'user_id' => $userId]);
    }

    /**
     * Joinable groups for one member, with the member's state in each: `member`, `pending` or `open`.
     *
     * @return list<array{id: int, name: string, description: string, state: string}>
     */
    public function joinable(int $userId): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'],
            'description' => (string) $row['description'], 'state' => $row['member'] !== null ? 'member'
                : ($row['pending'] !== null ? 'pending' : 'open')], $this->db->fetchAllAssociative(
                    'SELECT g.id, g.name, s.description, '
                        . '(SELECT 1 FROM usergroup_members m WHERE m.usergroup_id = g.id AND m.user_id = ?) '
                        . 'AS member, '
                        . "(SELECT 1 FROM group_applications a WHERE a.usergroup_id = g.id AND a.user_id = ? "
                        . "AND a.status = 'pending' LIMIT 1) AS pending FROM usergroups g INNER JOIN "
                        . 'usergroup_settings s ON s.usergroup_id = g.id WHERE s.joinable = 1 AND g.built_in = 0 '
                        . 'ORDER BY g.name, g.id LIMIT 200',
                    [$userId, $userId],
                ));
    }

    /** @throws RegistrationRejected `group_invalid` or `group_apply_refused` (member already, or still pending). */
    public function apply(int $userId, int $groupId, string $message): void
    {
        $state = null;

        foreach ($this->joinable($userId) as $group) {
            $state = $group['id'] === $groupId ? $group['state'] : $state;
        }

        if ($state === null) {
            throw new RegistrationRejected(['group_invalid']);
        }

        if ($state !== 'open') {
            throw new RegistrationRejected(['group_apply_refused']);
        }

        $this->db->insert('group_applications', ['usergroup_id' => $groupId, 'user_id' => $userId,
            'message' => mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $message) ?? ''), 0, 500),
            'status' => 'pending', 'created_at' => self::now()]);
    }

    /** True when the member leads at least one group (shows the queue link). */
    public function leads(int $userId): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM usergroup_leaders WHERE user_id = ? LIMIT 1', [$userId]) !== false;
    }

    /**
     * Pending applications the decider may decide: every one for an administrator, else those of led groups.
     *
     * @return list<array{id: int, group: string, user_id: int, username: string, message: string,
     *     created_at: string}>
     */
    public function queue(int $deciderId, bool $administrator): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'group' => (string) $row['name'],
            'user_id' => (int) $row['user_id'], 'username' => (string) $row['username'],
            'message' => (string) $row['message'], 'created_at' => (string) $row['created_at']],
            $this->db->fetchAllAssociative(
                'SELECT a.id, g.name, a.user_id, u.username, a.message, a.created_at '
                . 'FROM group_applications a INNER JOIN usergroups g ON g.id = a.usergroup_id '
                . "INNER JOIN users u ON u.id = a.user_id WHERE a.status = 'pending' "
                . ($administrator ? '' : 'AND EXISTS (SELECT 1 FROM usergroup_leaders l WHERE '
                    . 'l.usergroup_id = a.usergroup_id AND l.user_id = ?) ')
                . 'ORDER BY a.id LIMIT 100',
                $administrator ? [] : [$deciderId]
            )
        );
    }

    /**
     * Approves (adds the membership) or rejects one pending application.
     *
     * @throws RegistrationRejected `group_application_unknown` (missing, decided or not the decider's group).
     */
    public function decide(int $applicationId, bool $approve, int $deciderId, bool $administrator): void
    {
        $this->db->transactional(function (Connection $db) use (
            $applicationId,
            $approve,
            $deciderId,
            $administrator
): void {
            $row = $db->fetchAssociative(
                'SELECT a.usergroup_id, a.user_id FROM group_applications a '
                . "WHERE a.id = ? AND a.status = 'pending'" . ($administrator ? '' : ' AND EXISTS (SELECT 1 FROM '
                . 'usergroup_leaders l WHERE l.usergroup_id = a.usergroup_id AND l.user_id = ?)') . ' FOR UPDATE',
                $administrator ? [$applicationId] : [$applicationId, $deciderId]
            );

            if ($row === false || $deciderId === (int) $row['user_id']) {
                throw new RegistrationRejected(['group_application_unknown']);
            }

            $db->update('group_applications', ['status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $deciderId, 'decided_at' => self::now()], ['id' => $applicationId]);

            if ($approve) {
                (new DbalUsergroupRepository($db))->addMember((int) $row['user_id'], (int) $row['usergroup_id']);
            }
        });
    }

    /**
     * Adds a membership that ends at `$expires` (ADR-039). Granting again moves the end date.
     *
     * @throws RegistrationRejected `group_invalid`, `group_user_unknown` or `group_expiry_invalid`.
     */
    public function grantTimed(int $groupId, string $username, string $expires, int $grantedBy): void
    {
        $this->custom($groupId);
        $userId = $this->userId($username);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($expires), new DateTimeZone('UTC'));

        if ($date === false || $date <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new RegistrationRejected(['group_expiry_invalid']);
        }

        $this->db->transactional(static function (Connection $db) use ($groupId, $userId, $date, $grantedBy): void {
            (new DbalUsergroupRepository($db))->addMember($userId, $groupId);
            $db->delete('timed_memberships', ['user_id' => $userId, 'usergroup_id' => $groupId]);
            $db->insert('timed_memberships', ['user_id' => $userId, 'usergroup_id' => $groupId,
                'expires_at' => $date->format('Y-m-d 00:00:00.000000'), 'granted_by' => $grantedBy,
                'granted_at' => self::now()]);
        });
    }

    /** @return list<array{user_id: int, username: string, group_id: int, group: string, expires_at: string}> */
    public function timed(): array
    {
        return array_map(
            static fn(array $row): array => ['user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'], 'group_id' => (int) $row['usergroup_id'],
            'group' => (string) $row['name'], 'expires_at' => substr((string) $row['expires_at'], 0, 10)],
            $this->db->fetchAllAssociative('SELECT t.user_id, u.username, t.usergroup_id, g.name, t.expires_at '
                . 'FROM timed_memberships t INNER JOIN users u ON u.id = t.user_id INNER JOIN usergroups g '
            . 'ON g.id = t.usergroup_id ORDER BY t.expires_at, t.user_id LIMIT 200')
        );
    }

    /** Ends one timed membership now (the administrator's "end" button). */
    public function endTimed(int $groupId, int $userId): void
    {
        $this->expire([['user_id' => $userId, 'usergroup_id' => $groupId]]);
    }

    /** Background step: removes memberships whose time is over. Returns how many ended. */
    public function expireDue(): int
    {
        $rows = $this->db->fetchAllAssociative('SELECT user_id, usergroup_id FROM timed_memberships '
            . 'WHERE expires_at <= ? ORDER BY expires_at LIMIT ' . self::EXPIRY_BATCH, [self::now()]);
        $this->expire($rows);

        return count($rows);
    }

    /** @return list<array{id: int, group_id: int, group: string, min_posts: int, min_days: int,
     *     min_reputation: int}> */
    public function rules(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'],
            'group_id' => (int) $row['usergroup_id'], 'group' => (string) $row['name'],
            'min_posts' => (int) $row['min_posts'], 'min_days' => (int) $row['min_days'],
            'min_reputation' => (int) $row['min_reputation']], $this->db->fetchAllAssociative(
                'SELECT r.id, r.usergroup_id, g.name, r.min_posts, r.min_days, r.min_reputation '
                    . 'FROM group_promotion_rules r INNER JOIN usergroups g ON g.id = r.usergroup_id '
                    . 'ORDER BY g.name, r.id LIMIT 100',
            ));
    }

    /** @throws RegistrationRejected `group_invalid` or `group_rule_invalid` (all three limits zero). */
    public function addRule(int $groupId, int $posts, int $days, int $reputation): void
    {
        $this->custom($groupId);
        [$posts, $days, $reputation] = [max(0, min($posts, 1000000)), max(0, min($days, 36500)),
            max(0, min($reputation, 1000000))];

        if ($posts + $days + $reputation === 0) {
            throw new RegistrationRejected(['group_rule_invalid']);
        }

        $this->db->insert('group_promotion_rules', ['usergroup_id' => $groupId, 'min_posts' => $posts,
            'min_days' => $days, 'min_reputation' => $reputation, 'created_at' => self::now()]);
    }

    public function deleteRule(int $id): void
    {
        $this->db->delete('group_promotion_rules', ['id' => $id]);
    }

    /**
     * Background step: checks PROMOTION_BATCH members after the stored cursor against every rule and adds the
     * memberships they reached; the cursor wraps to the start after the last member. Promotion only adds.
     */
    public function promoteBatch(): int
    {
        $rules = $this->rules();

        if ($rules === []) {
            return 0;
        }

        $cursor = (int) $this->db->fetchOne("SELECT cursor_id FROM membership_task_state WHERE name = 'promotion'");
        $users = $this->db->fetchAllKeyValue("SELECT id, created_at FROM users WHERE id > ? AND status = 'active' "
            . 'ORDER BY id LIMIT ' . self::PROMOTION_BATCH, [$cursor]);
        $next = $users === [] ? 0 : (int) array_key_last($users);
        $added = 0;

        if ($users !== []) {
            $standing = (new MemberStanding($this->db))->standing(array_map('intval', array_keys($users)));
            $groups = new DbalUsergroupRepository($this->db);
            $now = time();

            foreach ($users as $id => $created) {
                $days = intdiv(max(0, $now - (int) strtotime($created . ' UTC')), 86400);

                foreach ($rules as $rule) {
                    if (
                        ($standing[(int) $id]['posts'] ?? 0) >= $rule['min_posts']
                        && ($standing[(int) $id]['reputation'] ?? 0) >= $rule['min_reputation']
                        && $days >= $rule['min_days']
                    ) {
                        try {
                            $groups->addMember((int) $id, $rule['group_id']);
                            $added++;
                        } catch (GroupRuleViolation) {
                            // The group was deleted meanwhile.
                        }
                    }
                }
            }
        }

        $this->db->executeStatement('DELETE FROM membership_task_state WHERE name = ?', ['promotion']);
        $this->db->insert('membership_task_state', ['name' => 'promotion', 'cursor_id' => $next,
            'updated_at' => self::now()]);

        return $added;
    }

    /** @throws RegistrationRejected `group_invalid` or `group_mail_invalid` (empty subject or body). */
    public function queueMail(int $groupId, int $authorId, string $subject, string $body): void
    {
        $this->custom($groupId, true);
        $subject = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject) ?? ''), 0, 150);
        $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 20000);

        if ($subject === '' || $body === '') {
            throw new RegistrationRejected(['group_mail_invalid']);
        }

        $this->db->insert('group_mail_jobs', ['usergroup_id' => $groupId, 'author_id' => $authorId,
            'subject' => $subject, 'body' => $body, 'status' => 'queued', 'created_at' => self::now()]);
    }

    /** @return list<array{id: int, group: string, subject: string, sent: int, status: string, created_at: string}> */
    public function mailJobs(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'group' => (string) $row['name'],
            'subject' => (string) $row['subject'], 'sent' => (int) $row['sent'], 'status' => (string) $row['status'],
            'created_at' => substr((string) $row['created_at'], 0, 16)], $this->db->fetchAllAssociative(
                'SELECT j.id, g.name, j.subject, j.sent, j.status, j.created_at FROM group_mail_jobs j '
                    . 'INNER JOIN usergroups g ON g.id = j.usergroup_id ORDER BY j.id DESC LIMIT 20',
            ));
    }

    /**
     * Background step: sends the next MAIL_BATCH messages of the oldest unfinished job to active members of the
     * group who did not turn group mail off. A failed send is skipped (the cursor still moves) so one bad address
     * never blocks the job.
     */
    public function sendMailBatch(Mailer $mailer): int
    {
        $job = $this->db->fetchAssociative("SELECT id, usergroup_id, subject, body, cursor_user_id FROM "
            . "group_mail_jobs WHERE status IN ('queued', 'sending') ORDER BY id LIMIT 1");

        if ($job === false) {
            return 0;
        }

        $recipients = $this->db->fetchAllKeyValue('SELECT u.id, u.email FROM usergroup_members m '
            . 'INNER JOIN users u ON u.id = m.user_id LEFT JOIN member_options o ON o.user_id = u.id '
            . "WHERE m.usergroup_id = ? AND u.id > ? AND u.status = 'active' AND COALESCE(o.group_mail, 1) = 1 "
            . 'ORDER BY u.id LIMIT ' . self::MAIL_BATCH, [(int) $job['usergroup_id'], (int) $job['cursor_user_id']]);
        $html = nl2br(htmlspecialchars((string) $job['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $sent = 0;

        foreach ($recipients as $email) {
            try {
                $mailer->send(new MailMessage((string) $email, (string) $job['subject'], (string) $job['body'], $html));
                $sent++;
            } catch (Throwable) {
                // Skipped; see the docblock.
            }
        }

        $done = count($recipients) < self::MAIL_BATCH;
        $this->db->executeStatement('UPDATE group_mail_jobs SET cursor_user_id = ?, sent = sent + ?, status = ?, '
            . 'finished_at = ? WHERE id = ?', [$recipients === [] ? (int) $job['cursor_user_id']
                : (int) array_key_last($recipients), $sent, $done ? 'done' : 'sending', $done ? self::now() : null,
                (int) $job['id']]);

        return $sent;
    }

    /** @param list<array<string, mixed>> $rows */
    private function expire(array $rows): void
    {
        $groups = new DbalUsergroupRepository($this->db);

        foreach ($rows as $row) {
            try {
                $groups->removeMember((int) $row['user_id'], (int) $row['usergroup_id']);
            } catch (GroupRuleViolation) {
                // The member made it the primary group meanwhile: the membership stays, only the timer ends.
            }

            $this->db->delete('timed_memberships', ['user_id' => (int) $row['user_id'],
                'usergroup_id' => (int) $row['usergroup_id']]);
        }
    }

    /**
     * @param bool $anyGroup True also allows built-in groups (group mail only).
     *
     * @throws RegistrationRejected `group_invalid`.
     */
    private function custom(int $groupId, bool $anyGroup = false): void
    {
        $builtIn = $this->db->fetchOne('SELECT built_in FROM usergroups WHERE id = ?', [$groupId]);

        if ($builtIn === false || (!$anyGroup && (int) $builtIn === 1) || $groupId === 1) {
            throw new RegistrationRejected(['group_invalid']);
        }
    }

    /** @throws RegistrationRejected `group_user_unknown`. */
    private function userId(string $username): int
    {
        $id = $this->db->fetchOne("SELECT id FROM users WHERE username = ? AND status = 'active'", [trim($username)]);

        if ($id === false) {
            throw new RegistrationRejected(['group_user_unknown']);
        }

        return (int) $id;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s.000000');
    }
}
