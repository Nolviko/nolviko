<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0080 (beta.18, ADR-042): administrator-defined content add-ons. `smileys` (short code plus aliases, image under
 * storage/site-images), `content_icons` (thread and post icons) and `custom_bbcodes` (MyCode: a tag pattern and an
 * allow-listed HTML template, compiled on read). Threads and posts get a nullable `icon_id`; no foreign key, so
 * the large tables are not rebuilt, and an unknown or removed icon is simply not shown.
 */
final class ContentTables implements Migration
{
    public function id(): string
    {
        return '0080_content_addons';
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
            'CREATE TABLE smileys (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) CHARACTER SET '
                . 'utf8mb4 COLLATE utf8mb4_bin NOT NULL, aliases VARCHAR(200) NULL, title VARCHAR(60) NOT NULL, '
                . 'image_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, mime VARCHAR(20) CHARACTER SET '
                . 'ascii COLLATE ascii_bin NOT NULL, width SMALLINT NOT NULL, height SMALLINT NOT NULL, '
                . 'position INT NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL, UNIQUE KEY uq_smileys_code '
                . '(code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE content_icons (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(60) NOT NULL, '
                . 'image_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, mime VARCHAR(20) CHARACTER SET '
                . 'ascii COLLATE ascii_bin NOT NULL, width SMALLINT NOT NULL, height SMALLINT NOT NULL, '
                . 'position INT NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE custom_bbcodes (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(20) CHARACTER '
                . 'SET ascii COLLATE ascii_bin NOT NULL, parameter VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin '
                . 'NOT NULL, template TEXT NOT NULL, description VARCHAR(200) NULL, enabled SMALLINT NOT NULL '
                . 'DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE KEY '
                . 'uq_custom_bbcodes_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'ALTER TABLE threads ADD COLUMN icon_id BIGINT NULL',
            'ALTER TABLE posts ADD COLUMN icon_id BIGINT NULL',
        ];
    }
}
