<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Conversations\Domain\ConversationRefused;
use Nolviko\Modules\Conversations\Domain\RecipientList;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;

/**
 * Every read/write checks membership; no moderator/admin override and no cross-conversation message lookup.
 *
 * Beta 17 G1: group conversations (up to `limits.message_recipients` recipients), hidden recipients (a member row
 * with `hidden` = 1 and `invited_by` = the sender; only that sender and that recipient ever see the row, and the
 * recipient's replies carry `visible_to` = the sender), removal from one member's own box (`removed_at`; a new
 * message the member can see brings the conversation back) and the `pm.max_conversations` box capacity.
 */
final class PrivateConversations
{
    /** Unread: a native (not imported) conversation with a message for the member after the read position. */
    private const UNREAD = '(c.import_job_id IS NULL AND EXISTS (SELECT 1 FROM private_messages unread '
        . 'WHERE unread.conversation_id = member.conversation_id AND unread.id > member.last_read_message_id '
        . 'AND (unread.visible_to IS NULL OR unread.visible_to = member.user_id) '
        . 'AND (unread.user_id IS NULL OR unread.user_id <> member.user_id)))';

    /** Imported conversations appear only once their import job has completed. */
    private const PUBLISHED = '(c.import_job_id IS NULL OR EXISTS (SELECT 1 FROM import_jobs j '
        . "WHERE j.id = c.import_job_id AND j.state = 'completed'))";

    public const UNREAD_CAP = 10;

    public const DEFAULT_RECIPIENTS = 10;

    public const CAPACITY = 'pm.max_conversations';

    public function __construct(
        private readonly Connection $connection,
        private readonly RateBuckets $buckets,
        private readonly ?MemberProfiles $profiles = null,
        private readonly int $intervalSeconds = 10,
        private readonly int $maxRecipients = self::DEFAULT_RECIPIENTS,
    ) {
    }

    public function maxRecipients(): int
    {
        return max(1, $this->maxRecipients);
    }

    /** Header badge: conversations with unread messages, counted only up to UNREAD_CAP. */
    public function unreadCount(int $actor): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT member.conversation_id FROM private_conversation_members member '
                . 'INNER JOIN private_conversations c ON c.id = member.conversation_id WHERE member.user_id = ? '
                . 'AND member.removed_at IS NULL AND ' . self::UNREAD . ' LIMIT ' . self::UNREAD_CAP
                . ') unread_conversations',
            [$actor],
        );
    }

    /**
     * The member's own box, newest first, 21 rows (one more than a page). With `$folder` only the conversations
     * the member filed in that folder of theirs.
     *
     * @return list<array<string, mixed>>
     */
    public function inbox(int $actor, ?int $before, ?int $folder = null): array
    {
        $parameters = [$actor];
        $filter = '';

        if ($folder !== null) {
            $filter = 'AND EXISTS (SELECT 1 FROM private_folder_items item INNER JOIN private_folders folder '
                . 'ON folder.id = item.folder_id WHERE item.conversation_id = c.id AND folder.id = ? '
                . 'AND folder.user_id = member.user_id) ';
            $parameters[] = $folder;
        }

        if ($before !== null) {
            $filter .= 'AND c.id < ? ';
            $parameters[] = $before;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT c.id, COALESCE(c.imported_subject, c.subject) AS subject, '
                . 'c.created_at, c.updated_at, ' . self::UNREAD . ' AS unread '
                . 'FROM private_conversation_members member '
                . 'INNER JOIN private_conversations c ON c.id = member.conversation_id WHERE member.user_id = ? '
                . 'AND member.removed_at IS NULL AND ' . self::PUBLISHED . ' ' . $filter
                . 'ORDER BY c.id DESC LIMIT 21',
            $parameters,
        );
    }

    /** @return array<string, mixed>|null */
    public function show(int $actor, int $id, ?int $before): ?array
    {
        $conversation = $this->connection->fetchAssociative(
            'SELECT c.id, COALESCE(c.imported_subject, c.subject) AS subject, c.import_job_id, member.hidden, '
                . 'member.invited_by FROM private_conversations c INNER JOIN private_conversation_members member '
                . 'ON member.conversation_id = c.id WHERE c.id = ? AND member.user_id = ? '
                . 'AND member.removed_at IS NULL AND ' . self::PUBLISHED,
            [$id, $actor],
        );

        if ($conversation === false) {
            return null;
        }

        // Membership and per-message visibility are repeated in message SQL, not trusted from the check above.
        $parameters = [$id, $actor, $actor, $actor];

        if ($before !== null) {
            $parameters[] = $before;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.content, page.created_at, page.visible_to, author.username AS author FROM ('
                . 'SELECT message.id, COALESCE(message.imported_content, message.content) AS content, '
                . 'message.created_at, message.user_id, message.visible_to '
                . 'FROM private_messages message '
                . 'WHERE message.conversation_id = ? AND EXISTS (SELECT 1 FROM private_conversation_members member '
                . 'WHERE member.conversation_id = message.conversation_id AND member.user_id = ? '
                . 'AND member.removed_at IS NULL) '
                . 'AND (message.visible_to IS NULL OR message.visible_to = ? OR message.user_id = ?) '
                . ($before === null ? '' : 'AND message.id < ? ') . 'ORDER BY message.id DESC LIMIT 51) page '
                . 'LEFT JOIN users author ON author.id = page.user_id ORDER BY page.id DESC',
            $parameters,
        );
        $older = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        if ($before === null && $rows !== []) {
            // The newest page was shown: everything up to its latest message is read. Never moves backwards.
            $latest = (int) $rows[0]['id'];
            $this->connection->executeStatement(
                'UPDATE private_conversation_members SET last_read_message_id = ? '
                    . 'WHERE conversation_id = ? AND user_id = ? AND last_read_message_id < ?',
                [$latest, $id, $actor, $latest],
            );
        }

        foreach ($rows as &$row) {
            $row['private'] = $row['visible_to'] !== null;
            unset($row['visible_to']);
        }

        unset($row);
        $conversation['older'] = $older ? (int) $rows[count($rows) - 1]['id'] : null;
        $conversation['messages'] = array_reverse($rows);
        $conversation['hidden'] = (int) $conversation['hidden'] === 1;
        $conversation['participants'] = $this->participants($actor, $id);
        $conversation['reply'] = $this->replyTarget($this->connection, $actor, $id, $conversation) !== false;
        unset($conversation['invited_by']);

        return $conversation;
    }

    /**
     * The members the actor may see: everyone who is not hidden, the actor, and the hidden recipients the actor
     * added. A hidden recipient never learns about other hidden recipients.
     *
     * @return list<array{name: ?string, hidden: bool, self: bool}>
     */
    public function participants(int $actor, int $id): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT member.user_id, member.hidden, u.username FROM private_conversation_members member '
                . 'LEFT JOIN users u ON u.id = member.user_id WHERE member.conversation_id = ? '
                . 'AND (member.hidden = 0 OR member.user_id = ? OR member.invited_by = ?) '
                . 'AND EXISTS (SELECT 1 FROM private_conversation_members me WHERE me.conversation_id = ? '
                . 'AND me.user_id = ?) ORDER BY member.hidden, u.username',
            [$id, $actor, $actor, $id, $actor],
        );

        return array_map(static fn(array $row): array => [
            'name' => $row['username'] === null ? null : (string) $row['username'],
            'hidden' => (int) $row['hidden'] === 1,
            'self' => (int) $row['user_id'] === $actor,
        ], $rows);
    }

    /**
     * Starts a conversation. `$recipient` and `$hidden` are user-name lists (RecipientList); the send throttle
     * applies once per message, whatever the number of recipients.
     *
     * @throws RegistrationRejected `conversation_content_invalid`.
     * @throws ConversationRefused Recipient, limit or box capacity refusals naming the recipient as typed.
     * @throws RegistrationThrottled While the sender must wait.
     */
    public function start(int $actor, string $recipient, string $subject, string $content, string $hidden = ''): int
    {
        self::text($subject, 160, false);
        self::text($content, 8000, true);
        $lists = RecipientList::both($recipient, $hidden, $this->maxRecipients());
        $this->throttle($actor);
        $names = $lists['visible'] + $lists['hidden'];
        $found = $this->connection->fetchAllAssociative(
            'SELECT id, username_normalized FROM users WHERE username_normalized IN (?'
                . str_repeat(', ?', count($names) - 1) . ") AND status = 'active'",
            array_map('strval', array_keys($names)),
        );
        $ids = [];

        foreach ($found as $row) {
            $ids[(string) $row['username_normalized']] = (int) $row['id'];
        }

        foreach ($names as $key => $typed) {
            if (!isset($ids[$key]) || $ids[$key] === $actor) {
                throw new ConversationRefused('conversation_recipient_unknown', ['name' => $typed]);
            }
        }

        return $this->connection->transactional(function (Connection $db) use (
            $actor,
            $ids,
            $names,
            $lists,
            $subject,
            $content,
        ): int {
            $all = [$actor, ...array_values($ids)];
            sort($all);
            $users = $db->fetchAllAssociative(
                'SELECT id, status FROM users WHERE id IN (?' . str_repeat(', ?', count($all) - 1)
                    . ') ORDER BY id FOR UPDATE',
                $all,
            );
            $active = [];

            foreach ($users as $row) {
                $active[(int) $row['id']] = $row['status'] === 'active';
            }

            if (!($active[$actor] ?? false)) {
                throw new RegistrationRejected(['conversation_recipient_invalid']);
            }

            foreach ($ids as $key => $user) {
                $allowed = ($active[$user] ?? false)
                    && ($this->profiles === null || $this->profiles->contactAllowed($actor, $user));

                if (!$allowed) {
                    throw new ConversationRefused('conversation_recipient_unknown', ['name' => $names[$key]]);
                }

                $limit = self::boxLimit($db, $user);

                if (
                    $limit > 0 && (int) $db->fetchOne(
                        'SELECT COUNT(*) FROM private_conversation_members WHERE user_id = ? AND removed_at IS NULL',
                        [$user],
                    ) >= $limit
                ) {
                    throw new ConversationRefused('conversation_box_full', ['name' => $names[$key]]);
                }
            }

            $now = self::now();
            $db->insert('private_conversations', [
                'subject' => trim($subject),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $db->lastInsertId();
            $db->insert('private_conversation_members', ['conversation_id' => $id, 'user_id' => $actor]);

            foreach ($ids as $key => $user) {
                $secret = isset($lists['hidden'][$key]);
                $db->insert('private_conversation_members', ['conversation_id' => $id, 'user_id' => $user,
                    'hidden' => $secret ? 1 : 0, 'invited_by' => $secret ? $actor : null]);
            }

            $db->insert('private_messages', ['conversation_id' => $id, 'user_id' => $actor,
                'content' => $content, 'created_at' => $now]);

            return $id;
        });
    }

    public function reply(int $actor, int $id, string $content): void
    {
        self::text($content, 8000, true);
        $this->throttle($actor);
        $this->connection->transactional(function (Connection $db) use ($actor, $id, $content): void {
            $active = $db->fetchOne("SELECT id FROM users WHERE id = ? AND status = 'active' FOR UPDATE", [$actor]);
            $member = $db->fetchAssociative(
                'SELECT user_id, hidden, invited_by FROM private_conversation_members WHERE conversation_id = ? '
                    . 'AND user_id = ? AND removed_at IS NULL FOR UPDATE',
                [$id, $actor],
            );

            if ($active === false || $member === false) {
                throw new RegistrationRejected(['conversation_not_found']);
            }

            $target = $this->replyTarget($db, $actor, $id, $member);

            if ($target === false) {
                throw new RegistrationRejected(['conversation_closed']);
            }

            if ($target['contact'] !== null && $this->profiles !== null) {
                if (!$this->profiles->contactAllowed($actor, $target['contact'])) {
                    throw new RegistrationRejected(['conversation_closed']);
                }
            }

            $now = self::now();
            $db->insert('private_messages', ['conversation_id' => $id, 'user_id' => $actor,
                'content' => $content, 'created_at' => $now, 'visible_to' => $target['only']]);
            $db->update('private_conversations', ['updated_at' => $now], ['id' => $id]);

            // A new message brings the conversation back to the boxes of members who removed it and can see it.
            if ($target['only'] === null) {
                $db->executeStatement(
                    'UPDATE private_conversation_members SET removed_at = NULL WHERE conversation_id = ? '
                        . 'AND user_id <> ? AND removed_at IS NOT NULL',
                    [$id, $actor],
                );
            } else {
                $db->executeStatement(
                    'UPDATE private_conversation_members SET removed_at = NULL WHERE conversation_id = ? '
                        . 'AND user_id = ? AND removed_at IS NOT NULL',
                    [$id, $target['only']],
                );
            }
        });
    }

    /**
     * Takes the conversation out of the actor's own box. Other members' boxes, the messages and the folders are
     * untouched; the conversation returns when a new message the actor can see arrives.
     *
     * @throws RegistrationRejected `conversation_not_found`.
     */
    public function remove(int $actor, int $id): void
    {
        $changed = $this->connection->executeStatement(
            'UPDATE private_conversation_members SET removed_at = ? WHERE conversation_id = ? AND user_id = ? '
                . 'AND removed_at IS NULL',
            [self::now(), $id, $actor],
        );

        if ($changed !== 1) {
            throw new RegistrationRejected(['conversation_not_found']);
        }
    }

    /**
     * Box capacity of a member in conversations, 0 = unlimited. A user rule wins; otherwise any of the member's
     * groups without a limit (no rule, or 0) leaves the box unlimited, else the largest group limit applies.
     */
    public static function boxLimit(Connection $db, int $user): int
    {
        $own = $db->fetchOne(
            'SELECT value FROM user_permission_overrides WHERE user_id = ? AND permission_key = ?',
            [$user, self::CAPACITY],
        );

        if ($own !== false && ctype_digit((string) $own)) {
            return (int) $own;
        }

        $values = $db->fetchFirstColumn(
            'SELECT p.value FROM usergroup_members m LEFT JOIN usergroup_permissions p '
                . 'ON p.usergroup_id = m.usergroup_id AND p.permission_key = ? WHERE m.user_id = ?',
            [self::CAPACITY, $user],
        );
        $limit = 0;

        foreach ($values as $value) {
            if ($value === null || !ctype_digit((string) $value) || (int) $value === 0) {
                return 0;
            }

            $limit = max($limit, (int) $value);
        }

        return $limit;
    }

    /**
     * Who a reply from the actor reaches: false when nobody can receive it (closed). `only` is the one member a
     * hidden recipient's reply is limited to; `contact` the single other member whose contact rules apply.
     *
     * @param array<string, mixed> $member The actor's membership row (`hidden`, `invited_by`).
     *
     * @return array{only: ?int, contact: ?int}|false
     */
    private function replyTarget(Connection $db, int $actor, int $id, array $member): array|false
    {
        if ((int) $member['hidden'] === 1) {
            $inviter = $member['invited_by'] === null ? null : (int) $member['invited_by'];
            $present = $inviter !== null && $db->fetchOne(
                'SELECT user_id FROM private_conversation_members WHERE conversation_id = ? AND user_id = ?',
                [$id, $inviter],
            ) !== false;

            return $present ? ['only' => $inviter, 'contact' => $inviter] : false;
        }

        $others = $db->fetchFirstColumn(
            'SELECT user_id FROM private_conversation_members WHERE conversation_id = ? AND user_id <> ? LIMIT 2',
            [$id, $actor],
        );

        if ($others === []) {
            return false;
        }

        return ['only' => null, 'contact' => count($others) === 1 ? (int) $others[0] : null];
    }

    private function throttle(int $actor): void
    {
        if ($this->intervalSeconds < 1) {
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = 'conversation.send:' . $actor;
        $this->buckets->ensure($key, $now);
        $wait = $this->buckets->hit($key, $this->intervalSeconds, $now);

        if ($wait > 0) {
            throw new RegistrationThrottled($wait);
        }
    }

    private static function text(string $text, int $maximum, bool $multiline): void
    {
        $controls = $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';

        if (
            strlen($text) > $maximum * 4 || !mb_check_encoding($text, 'UTF-8') || trim($text) === ''
            || grapheme_strlen($text) > $maximum || preg_match($controls, $text) === 1
        ) {
            throw new RegistrationRejected(['conversation_content_invalid']);
        }
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
