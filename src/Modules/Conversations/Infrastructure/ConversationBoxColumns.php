<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Beta 17 G1: per-member box state and hidden (BCC) recipients. `removed_at` takes a conversation out of one
 * member's own box only; `hidden` marks a hidden recipient and `invited_by` the sender who added them. A message
 * with `visible_to` set is seen only by its author and that member (a hidden recipient's reply to the sender).
 * Neither ID column has a foreign key on purpose: a deleted account must never turn a private reply public.
 */
final class ConversationBoxColumns implements Migration
{
    public function id(): string
    {
        return '0060_conversation_box';
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
            'ALTER TABLE private_conversation_members ADD COLUMN removed_at DATETIME(6) NULL',
            'ALTER TABLE private_conversation_members ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0',
            'ALTER TABLE private_conversation_members ADD COLUMN invited_by BIGINT NULL',
            'ALTER TABLE private_messages ADD COLUMN visible_to BIGINT NULL',
        ];
    }
}
