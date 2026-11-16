<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Derived sitemap page boundaries only; URLs/content always pass current guest visibility again when served. */
final class SeoTables implements Migration
{
    public function id(): string
    {
        return '0043_seo_sitemaps';
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
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'CREATE TABLE seo_sitemap_runs (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, '
                . 'active_slot SMALLINT NULL UNIQUE, rules_version BIGINT NOT NULL, '
                    . 'origin_hash CHAR(64) NOT NULL, '
                . "state VARCHAR(16) NOT NULL DEFAULT 'running', cursor_id BIGINT NOT NULL DEFAULT 0, "
                . 'watermark BIGINT NOT NULL, page_count INT NOT NULL DEFAULT 0, '
                . 'created_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, '
                . 'INDEX ix_sitemap_gc (active_slot, created_at, id))' . $options,
            'CREATE TABLE seo_sitemap_pages (run_id BIGINT NOT NULL, ordinal INT NOT NULL, '
                . 'first_id BIGINT NOT NULL, last_id BIGINT NOT NULL, '
                . 'PRIMARY KEY (run_id, ordinal), CONSTRAINT fk_sitemap_run FOREIGN KEY (run_id) '
                . 'REFERENCES seo_sitemap_runs(id) ON DELETE CASCADE)' . $options,
        ];
    }
}
