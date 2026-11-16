<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Private membership owns access. Account deletion removes membership and anonymizes retained message authors. */
final class ConversationsTable implements Migration
{
    public function id(): string
    {
        return '0034_conversations';
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
            'CREATE TABLE private_conversations (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'subject VARCHAR(160) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL)'
                . $table,
            'CREATE TABLE private_conversation_members (conversation_id BIGINT NOT NULL, user_id BIGINT NOT NULL, '
                . 'PRIMARY KEY (conversation_id, user_id), INDEX ix_private_members_user (user_id, conversation_id), '
                . 'CONSTRAINT fk_private_members_conversation FOREIGN KEY (conversation_id) '
                . 'REFERENCES private_conversations (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_private_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)'
                . $table,
            'CREATE TABLE private_messages (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'conversation_id BIGINT NOT NULL, user_id BIGINT NULL, content TEXT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, INDEX ix_private_messages_conversation (conversation_id, id), '
                . 'INDEX ix_private_messages_user (user_id, id), '
                . 'CONSTRAINT fk_private_messages_conversation FOREIGN KEY (conversation_id) '
                . 'REFERENCES private_conversations (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_private_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL)'
                . $table,
        ];
    }
}
