<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Conversations\Domain\ConversationRefused;
use Nolviko\Modules\Conversations\Domain\RecipientList;

/**
 * Unsent private messages on the server (beta 17 G1): recipients, hidden recipients, subject and content as the
 * member typed them. Only the owner reads, changes or deletes a draft; sending one deletes it.
 */
final class PrivateDrafts
{
    public const MAX_DRAFTS = 50;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> Newest first. */
    public function all(int $user): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, recipients, hidden_recipients, subject, updated_at FROM private_drafts WHERE user_id = ? '
                . 'ORDER BY updated_at DESC, id DESC LIMIT ' . self::MAX_DRAFTS,
            [$user],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $user, int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, recipients, hidden_recipients, subject, content FROM private_drafts '
                . 'WHERE id = ? AND user_id = ?',
            [$id, $user],
        );

        return $row === false ? null : $row;
    }

    /**
     * Creates (`$id` null) or replaces one of the user's drafts and returns its ID.
     *
     * @throws ConversationRefused `conversation_draft_invalid`, `conversation_draft_not_found` or
     *     `conversation_drafts_full` (`limit`).
     */
    public function save(
        int $user,
        ?int $id,
        string $recipients,
        string $hidden,
        string $subject,
        string $content,
    ): int {
        $fields = [[$recipients, RecipientList::MAX_BYTES, true], [$hidden, RecipientList::MAX_BYTES, true],
            [$subject, 160, false], [$content, 8000, true]];

        foreach ($fields as [$text, $limit, $multiline]) {
            $controls = $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';

            if (
                strlen($text) > $limit * 4 || !mb_check_encoding($text, 'UTF-8')
                || grapheme_strlen($text) > $limit || preg_match($controls, $text) === 1
            ) {
                throw new ConversationRefused('conversation_draft_invalid');
            }
        }

        if (trim($recipients . $hidden . $subject . $content) === '') {
            throw new ConversationRefused('conversation_draft_invalid');
        }

        $values = ['recipients' => trim($recipients), 'hidden_recipients' => trim($hidden),
            'subject' => trim($subject), 'content' => $content,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')];

        return $this->connection->transactional(function (Connection $db) use ($user, $id, $values): int {
            $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);

            if ($id !== null) {
                $owned = $db->fetchOne('SELECT id FROM private_drafts WHERE id = ? AND user_id = ?', [$id, $user]);

                if ($owned === false) {
                    throw new ConversationRefused('conversation_draft_not_found');
                }

                $db->update('private_drafts', $values, ['id' => $id, 'user_id' => $user]);

                return $id;
            }

            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM private_drafts WHERE user_id = ?', [$user]);

            if ($count >= self::MAX_DRAFTS) {
                throw new ConversationRefused('conversation_drafts_full', ['limit' => self::MAX_DRAFTS]);
            }

            $db->insert('private_drafts', $values + ['user_id' => $user, 'created_at' => $values['updated_at']]);

            return (int) $db->lastInsertId();
        });
    }

    public function delete(int $user, int $id): void
    {
        $this->connection->delete('private_drafts', ['id' => $id, 'user_id' => $user]);
    }
}
