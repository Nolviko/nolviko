<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * beta.18 forum settings (G4): link sections (`link_url`, optional click counter), whether posts raise the
 * member post count (`counts_posts`, on for every existing forum), a hashed forum password (ADR-041) and guest
 * posting (ADR-040, off). One clause per ALTER TABLE, as unattended updates require.
 */
final class ForumExtrasColumns implements Migration
{
    public const ID = '0075_forum_extras';

    public function id(): string
    {
        return self::ID;
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
            'ALTER TABLE forums ADD COLUMN link_url VARCHAR(2000) NULL',
            'ALTER TABLE forums ADD COLUMN link_counts TINYINT(1) NOT NULL DEFAULT 0',
            'ALTER TABLE forums ADD COLUMN link_clicks BIGINT NOT NULL DEFAULT 0',
            'ALTER TABLE forums ADD COLUMN counts_posts TINYINT(1) NOT NULL DEFAULT 1',
            'ALTER TABLE forums ADD COLUMN password_hash VARCHAR(255) NULL',
            'ALTER TABLE forums ADD COLUMN guest_posting TINYINT(1) NOT NULL DEFAULT 0',
        ];
    }
}
