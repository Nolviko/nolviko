<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Custom profile fields and post-count ranks (2026-11-08, MemberStanding). Reputation and badges are computed
 * from posts and reactions, so they need no table.
 */
final class MemberStandingTables implements Migration
{
    public function id(): string
    {
        return '0048_member_standing';
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
            'CREATE TABLE profile_fields (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(60) NOT NULL, '
                . 'kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at DATETIME(6) NOT NULL) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE profile_field_values (user_id BIGINT NOT NULL, field_id BIGINT NOT NULL, '
                . 'value VARCHAR(255) NOT NULL, PRIMARY KEY (user_id, field_id), '
                . 'CONSTRAINT fk_profile_values_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_profile_values_field FOREIGN KEY (field_id) REFERENCES profile_fields (id) '
                . 'ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_ranks (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(60) NOT NULL, '
                . 'min_posts INT NOT NULL, color VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, INDEX ix_member_ranks_posts (min_posts)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
