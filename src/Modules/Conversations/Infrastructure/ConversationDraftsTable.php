<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Beta 17 G1: unsent private messages kept on the server, so a member can continue one in another session. */
final class ConversationDraftsTable implements Migration
{
    public function id(): string
    {
        return '0062_conversation_drafts';
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

        return [
            'CREATE TABLE private_drafts (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, '
                . 'recipients VARCHAR(1000) NOT NULL, hidden_recipients VARCHAR(1000) NOT NULL, '
                . 'subject VARCHAR(160) NOT NULL, content TEXT NOT NULL, created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, INDEX ix_private_drafts_user (user_id, updated_at), '
                . 'CONSTRAINT fk_private_drafts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)'
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
