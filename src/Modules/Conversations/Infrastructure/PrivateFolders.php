<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Conversations\Domain\ConversationRefused;

/**
 * Personal message folders (beta 17 G1). Every query is scoped to the folder's owner; filing needs the owner's
 * own membership of the conversation. Folders and labels share this model: a conversation may sit in several
 * folders. Importers call ensure() with their source folder or label key and file() for each conversation.
 */
final class PrivateFolders
{
    public const MAX_FOLDERS = 50;

    public const MAX_NAME = 60;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array{id: int, name: string}> */
    public function all(int $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM private_folders WHERE user_id = ? ORDER BY name, id LIMIT ' . self::MAX_FOLDERS,
            [$user],
        );

        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows,
        );
    }

    public function name(int $user, int $folder): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM private_folders WHERE id = ? AND user_id = ?',
            [$folder, $user],
        );

        return $name === false ? null : (string) $name;
    }

    /** @return list<int> Folder IDs of the user that hold the conversation. */
    public function holding(int $user, int $conversation): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT folder.id FROM private_folders folder INNER JOIN private_folder_items item '
                . 'ON item.folder_id = folder.id WHERE folder.user_id = ? AND item.conversation_id = ?',
            [$user, $conversation],
        ));
    }

    /**
     * @throws ConversationRefused `conversation_folder_invalid`, `conversation_folder_exists` (`name`) or
     *     `conversation_folders_full` (`limit`).
     */
    public function create(int $user, string $name, ?string $sourceKey = null): int
    {
        $name = self::validName($name);

        return $this->connection->transactional(function (Connection $db) use ($user, $name, $sourceKey): int {
            $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM private_folders WHERE user_id = ?', [$user]);

            if ($count >= self::MAX_FOLDERS) {
                throw new ConversationRefused('conversation_folders_full', ['limit' => self::MAX_FOLDERS]);
            }

            $this->assertFree($db, $user, $name, null);
            $db->insert('private_folders', ['user_id' => $user, 'name' => $name,
                'source_key' => $sourceKey === null ? null : mb_substr($sourceKey, 0, 100, 'UTF-8'),
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);

            return (int) $db->lastInsertId();
        });
    }

    /** Importer entry: the user's folder with this source key (or this name), created when missing. */
    public function ensure(int $user, string $name, string $sourceKey): int
    {
        $found = $this->connection->fetchOne(
            'SELECT id FROM private_folders WHERE user_id = ? AND (source_key = ? OR name = ?) ORDER BY id LIMIT 1',
            [$user, mb_substr($sourceKey, 0, 100, 'UTF-8'), self::validName($name)],
        );

        return $found === false ? $this->create($user, $name, $sourceKey) : (int) $found;
    }

    /** @throws ConversationRefused `conversation_folder_invalid`, `conversation_folder_exists`. */
    public function rename(int $user, int $folder, string $name): void
    {
        $name = self::validName($name);
        $this->connection->transactional(function (Connection $db) use ($user, $folder, $name): void {
            self::owned($db, $user, $folder);
            $this->assertFree($db, $user, $name, $folder);
            $db->update('private_folders', ['name' => $name], ['id' => $folder, 'user_id' => $user]);
        });
    }

    /** Deletes the folder only; its conversations stay in the box. */
    public function delete(int $user, int $folder): void
    {
        $this->connection->transactional(function (Connection $db) use ($user, $folder): void {
            self::owned($db, $user, $folder);
            $db->delete('private_folder_items', ['folder_id' => $folder]);
            $db->delete('private_folders', ['id' => $folder, 'user_id' => $user]);
        });
    }

    /** @throws ConversationRefused `conversation_folder_invalid` when the folder or membership is not the user's. */
    public function file(int $user, int $folder, int $conversation): void
    {
        $this->connection->transactional(function (Connection $db) use ($user, $folder, $conversation): void {
            self::owned($db, $user, $folder);
            $member = $db->fetchOne(
                'SELECT user_id FROM private_conversation_members WHERE conversation_id = ? AND user_id = ?',
                [$conversation, $user],
            );

            if ($member === false) {
                throw new ConversationRefused('conversation_folder_invalid');
            }

            $present = $db->fetchOne(
                'SELECT folder_id FROM private_folder_items WHERE folder_id = ? AND conversation_id = ?',
                [$folder, $conversation],
            );

            if ($present === false) {
                $db->insert('private_folder_items', ['folder_id' => $folder, 'conversation_id' => $conversation]);
            }
        });
    }

    public function unfile(int $user, int $folder, int $conversation): void
    {
        $this->connection->transactional(function (Connection $db) use ($user, $folder, $conversation): void {
            self::owned($db, $user, $folder);
            $db->delete('private_folder_items', ['folder_id' => $folder, 'conversation_id' => $conversation]);
        });
    }

    private static function owned(Connection $db, int $user, int $folder): void
    {
        $found = $db->fetchOne(
            'SELECT id FROM private_folders WHERE id = ? AND user_id = ? FOR UPDATE',
            [$folder, $user],
        );

        if ($found === false) {
            throw new ConversationRefused('conversation_folder_invalid');
        }
    }

    private function assertFree(Connection $db, int $user, string $name, ?int $except): void
    {
        $other = $db->fetchOne('SELECT id FROM private_folders WHERE user_id = ? AND name = ?', [$user, $name]);

        if ($other !== false && (int) $other !== $except) {
            throw new ConversationRefused('conversation_folder_exists', ['name' => $name]);
        }
    }

    private static function validName(string $name): string
    {
        $name = trim($name);

        if (
            $name === '' || strlen($name) > self::MAX_NAME * 4 || !mb_check_encoding($name, 'UTF-8')
            || grapheme_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
        ) {
            throw new ConversationRefused('conversation_folder_invalid');
        }

        return $name;
    }
}
