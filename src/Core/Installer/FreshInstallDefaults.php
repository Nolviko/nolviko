<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Doctrine\DBAL\Connection;

/**
 * Defaults that only a fresh install sets (2026-11-16): data changes that an unattended update must not make
 * (ExpandOnlyPolicy), because an administrator may have chosen something else. Run by the installer when the
 * schema is complete; idempotent, and it changes only a value that still has its old built-in setting.
 */
final class FreshInstallDefaults
{
    /** Registered members' built-in group (BuiltInGroup::Registered). */
    private const REGISTERED = 2;

    public static function apply(Connection $db): void
    {
        // Owner decision 2026-11-09: authors may edit their posts without a time limit (0 = no limit); the old
        // built-in rule was 60 minutes (0050_edit_window_unlimited, statement-free since 2026-11-16).
        $db->executeStatement(
            "UPDATE usergroup_permissions SET value = '0' WHERE usergroup_id = ? "
                . "AND permission_key = 'forum.post.edit_window_minutes' AND value = '60'",
            [self::REGISTERED],
        );
    }
}
