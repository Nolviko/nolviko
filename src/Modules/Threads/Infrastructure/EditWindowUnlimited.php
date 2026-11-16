<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Owner decision 2026-11-09: authors may edit their posts without a time limit (`forum.post.edit_window_minutes`
 * 0 = no limit). The built-in 60-minute rule of registered members becomes 0; a value an administrator chose
 * stays as it is.
 */
final class EditWindowUnlimited implements Migration
{
    public function id(): string
    {
        return '0050_edit_window_unlimited';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    /**
     * 2026-11-16: no statement. Changing an administrator-visible permission is not something an unattended update
     * should do (ExpandOnlyPolicy), so the new default reaches fresh installs through FreshInstallDefaults and a
     * site updated from 0.1.0-beta.1 keeps its 60 minutes until an administrator changes it.
     */
    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return [];
    }
}
