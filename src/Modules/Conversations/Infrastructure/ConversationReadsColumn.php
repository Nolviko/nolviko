<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Per-member read position for private conversations (header badge, inbox marker). Existing memberships start as
 * read up to their latest message, so an upgrade does not flag every old conversation as new.
 */
final class ConversationReadsColumn implements Migration
{
    public function id(): string
    {
        return '0044_conversation_reads';
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
            // 2026-11-16: no data backfill, so unattended updates may apply it (ExpandOnlyPolicy). It never
            // shipped with one: a site coming from 0.1.0-beta.1 sees its older messages as unread once.
            'ALTER TABLE private_conversation_members ADD COLUMN last_read_message_id BIGINT NOT NULL DEFAULT 0',
        ];
    }
}
