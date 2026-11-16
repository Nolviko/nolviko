<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

final class AdminSavedFiltersTable implements Migration
{
    public function id(): string
    {
        return '0036_admin_saved_filters';
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

        return ['CREATE TABLE admin_saved_filters ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, '
            . 'name VARCHAR(80) NOT NULL, name_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'context VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, filters_json TEXT NOT NULL, '
            . 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, '
            . 'CONSTRAINT uq_admin_saved_filter UNIQUE (user_id, name_hash), '
            . 'CONSTRAINT fk_admin_saved_filter_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'];
    }
}
