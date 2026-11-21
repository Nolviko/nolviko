<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** 0083 (beta.18): the admin attachment inventory lists the newest files first without sorting the whole table. */
final class MediaInventoryIndex implements Migration
{
    public function id(): string
    {
        return '0083_media_inventory';
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

        return ['CREATE INDEX ix_media_created ON community_media (created_at, id)'];
    }
}
