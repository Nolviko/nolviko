<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;

/** Every read/write checks membership; no moderator/admin override and no cross-conversation message lookup. */
final class PrivateConversations
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RateBuckets $buckets,
        private readonly ?MemberProfiles $profiles = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    /** Unread: a native (not imported) conversation with a message from someone else after the read position. */
    private const UNREAD = '(c.import_job_id IS NULL AND EXISTS (SELECT 1 FROM private_messages unread '
        . 'WHERE unread.conversation_id = member.conversation_id AND unread.id > member.last_read_message_id '
        . 'AND (unread.user_id IS NULL OR unread.user_id <> member.user_id)))';

    public const UNREAD_CAP = 10;

    /** Header badge: conversations with unread messages, counted only up to UNREAD_CAP. */
    public function unreadCount(int $actor): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT member.conversation_id FROM private_conversation_members member '
                . 'INNER JOIN private_conversations c ON c.id = member.conversation_id WHERE member.user_id = ? AND '
                . self::UNREAD . ' LIMIT ' . self::UNREAD_CAP . ') unread_conversations',
            [$actor],
        );
    }

    public function inbox(int $actor, ?int $before): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.id, COALESCE(c.imported_subject, c.subject) AS subject, '
                . 'c.created_at, c.updated_at, ' . self::UNREAD . ' AS unread '
                . 'FROM private_conversation_members member '
                . 'INNER JOIN private_conversations c ON c.id = member.conversation_id WHERE member.user_id = ? '
                . 'AND (c.import_job_id IS NULL OR EXISTS (SELECT 1 FROM import_jobs j WHERE j.id = c.import_job_id '
                . "AND j.state = 'completed')) "
                . ($before === null ? '' : 'AND c.id < ? ') . 'ORDER BY c.id DESC LIMIT 21',
            $before === null ? [$actor] : [$actor, $before],
        );
    }

    /** @return array<string, mixed>|null */
    public function show(int $actor, int $id, ?int $before): ?array
    {
        $conversation = $this->connection->fetchAssociative(
            'SELECT c.id, COALESCE(c.imported_subject, c.subject) AS subject, '
                . 'c.import_job_id FROM private_conversations c INNER JOIN private_conversation_members member '
                . 'ON member.conversation_id = c.id WHERE c.id = ? AND member.user_id = ? '
                . 'AND (c.import_job_id IS NULL OR EXISTS (SELECT 1 FROM import_jobs j WHERE j.id = c.import_job_id '
                . "AND j.state = 'completed'))",
            [$id, $actor],
        );

        if ($conversation === false) {
            return null;
        }

        // Membership is repeated in message SQL, not trusted from the earlier existence check.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.content, page.created_at, author.username AS author FROM ('
                . 'SELECT message.id, COALESCE(message.imported_content, message.content) AS content, '
                . 'message.created_at, message.user_id '
                . 'FROM private_messages message '
                . 'WHERE message.conversation_id = ? AND EXISTS (SELECT 1 FROM private_conversation_members member '
                . 'WHERE member.conversation_id = message.conversation_id AND member.user_id = ?) '
                . ($before === null ? '' : 'AND message.id < ? ') . 'ORDER BY message.id DESC LIMIT 51) page '
                . 'LEFT JOIN users author ON author.id = page.user_id ORDER BY page.id DESC',
            $before === null ? [$id, $actor] : [$id, $actor, $before],
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
        $conversation['older'] = $older ? (int) $rows[count($rows) - 1]['id'] : null;
        $conversation['messages'] = array_reverse($rows);
        $conversation['reply'] = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM private_conversation_members WHERE conversation_id = ?',
            [$id],
        ) === 2;

        return $conversation;
    }

    public function start(int $actor, string $recipient, string $subject, string $content): int
    {
        $this->throttle($actor);
        self::text($subject, 160, false);

        if (mb_strlen($subject, 'UTF-8') > 160) {
            throw new RegistrationRejected(['conversation_content_invalid']);
        }
        self::text($content, 8000, true);

        if (strlen($recipient) > 256 || !mb_check_encoding($recipient, 'UTF-8')) {
            throw new RegistrationRejected(['conversation_recipient_invalid']);
        }

        $recipientId = $this->connection->fetchOne(
            "SELECT id FROM users WHERE username_normalized = ? AND status = 'active'",
            [IdentityNormalizer::username(trim($recipient))],
        );

        if ($recipientId === false || (int) $recipientId === $actor) {
            throw new RegistrationRejected(['conversation_recipient_invalid']);
        }

        return $this->connection->transactional(function (Connection $db) use (
            $actor,
            $recipientId,
            $subject,
            $content,
        ): int {
            $users = $db->fetchAllAssociative(
                'SELECT id, status FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                [$actor, $recipientId]
            );

            if (
                count($users) !== 2
                || array_filter($users, static fn(array $row): bool => $row['status'] !== 'active')
            ) {
                throw new RegistrationRejected(['conversation_recipient_invalid']);
            }

            if ($this->profiles !== null && !$this->profiles->contactAllowed($actor, (int) $recipientId)) {
                throw new RegistrationRejected(['conversation_recipient_invalid']);
            }

            $now = self::now();
            $db->insert('private_conversations', [
                'subject' => trim($subject),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $db->lastInsertId();

            foreach ([$actor, (int) $recipientId] as $user) {
                $db->insert('private_conversation_members', ['conversation_id' => $id, 'user_id' => $user]);
            }

            $db->insert('private_messages', ['conversation_id' => $id, 'user_id' => $actor,
                'content' => $content, 'created_at' => $now]);

            return $id;
        });
    }

    public function reply(int $actor, int $id, string $content): void
    {
        $this->throttle($actor);
        self::text($content, 8000, true);
        $this->connection->transactional(function (Connection $db) use ($actor, $id, $content): void {
            $active = $db->fetchOne("SELECT id FROM users WHERE id = ? AND status = 'active' FOR UPDATE", [$actor]);
            $member = $db->fetchOne(
                'SELECT user_id FROM private_conversation_members WHERE conversation_id = ? AND user_id = ? FOR UPDATE',
                [$id, $actor],
            );

            if ($active === false || $member === false) {
                throw new RegistrationRejected(['conversation_not_found']);
            }

            if (
                (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM private_conversation_members WHERE conversation_id = ?',
                    [$id]
                ) !== 2
            ) {
                throw new RegistrationRejected(['conversation_closed']);
            }

            $recipient = (int) $db->fetchOne('SELECT user_id FROM private_conversation_members '
                . 'WHERE conversation_id = ? AND user_id <> ?', [$id, $actor]);

            if ($this->profiles !== null && !$this->profiles->contactAllowed($actor, $recipient)) {
                throw new RegistrationRejected(['conversation_closed']);
            }

            $now = self::now();
            $db->insert('private_messages', ['conversation_id' => $id, 'user_id' => $actor,
                'content' => $content, 'created_at' => $now]);
            $db->update('private_conversations', ['updated_at' => $now], ['id' => $id]);
        });
    }

    private function throttle(int $actor): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = 'conversation.send:' . $actor;
        $this->buckets->ensure($key, $now);
        $wait = $this->buckets->hit($key, 10, $now);

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
