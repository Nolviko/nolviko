<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Beta 17 G1: personal message folders. One model for folders and labels: a folder belongs to one member and a
 * conversation may sit in several of that member's folders, so an importer maps a source folder (one per
 * conversation) and source labels (many) onto the same rows. `source_key` keeps the importer's own folder key.
 */
final class ConversationFoldersTable implements Migration
{
    public function id(): string
    {
        return '0061_conversation_folders';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            'CREATE TABLE private_folders (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, '
                . 'name VARCHAR(60) NOT NULL, source_key VARCHAR(100) NULL, created_at DATETIME(6) NOT NULL, '
                . 'UNIQUE KEY uq_private_folders_name (user_id, name), '
                . 'CONSTRAINT fk_private_folders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)'
                . $table,
            'CREATE TABLE private_folder_items (folder_id BIGINT NOT NULL, conversation_id BIGINT NOT NULL, '
                . 'PRIMARY KEY (folder_id, conversation_id), INDEX ix_private_folder_items_conversation '
                . '(conversation_id), CONSTRAINT fk_private_folder_items_folder FOREIGN KEY (folder_id) '
                . 'REFERENCES private_folders (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_private_folder_items_conversation FOREIGN KEY (conversation_id) '
                . 'REFERENCES private_conversations (id) ON DELETE CASCADE)' . $table,
        ];
    }
}
