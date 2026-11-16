<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Additive account preference; old accounts follow the operating system until they choose a palette. */
final class UsersAppearanceColumn implements Migration
{
    public function id(): string
    {
        return '0038_users_appearance';
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
        return ["ALTER TABLE users ADD COLUMN theme_preference VARCHAR(6) NOT NULL DEFAULT 'system'"];
    }
}
