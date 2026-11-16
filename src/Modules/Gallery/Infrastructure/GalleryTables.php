<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Media gallery (2026-11-08): members' albums (visibility public, members or private) and their images. Files live
 * under storage/gallery with random keys; rows keep only metadata. Comments, reactions and video are not part of it.
 */
final class GalleryTables implements Migration
{
    public function id(): string
    {
        return '0047_media_gallery';
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
            'CREATE TABLE gallery_albums (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, '
                . 'title VARCHAR(100) NOT NULL, description VARCHAR(500) NULL, visibility VARCHAR(12) CHARACTER SET '
                . 'ascii COLLATE ascii_bin NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, '
                . 'INDEX ix_gallery_albums_user (user_id, id), INDEX ix_gallery_albums_visibility (visibility, id), '
                . 'CONSTRAINT fk_gallery_albums_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE gallery_items (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, album_id BIGINT NOT NULL, '
                . 'user_id BIGINT NOT NULL, title VARCHAR(100) NOT NULL, file_key CHAR(64) CHARACTER SET ascii '
                . 'COLLATE ascii_bin NOT NULL, thumb_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, bytes BIGINT NOT NULL, '
                . 'width INT NOT NULL, height INT NOT NULL, status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin '
                . 'NOT NULL, created_at DATETIME(6) NOT NULL, INDEX ix_gallery_items_album (album_id, id), '
                . 'INDEX ix_gallery_items_user (user_id, id), INDEX ix_gallery_items_status (status, id), '
                . 'CONSTRAINT fk_gallery_items_album FOREIGN KEY (album_id) REFERENCES gallery_albums (id) '
                . 'ON DELETE CASCADE, CONSTRAINT fk_gallery_items_user FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
