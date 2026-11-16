<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Gallery\Domain\GalleryRejected;
use Nolviko\Modules\Gallery\Domain\GalleryRules;
use Nolviko\Modules\Gallery\Domain\GalleryViewer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Media gallery store (2026-11-08). Rules:
 * - Members create albums and upload images into their own albums; administrators see and manage everything.
 * - Album visibility: `public` (everyone), `members` (signed-in), `private` (owner and administrators).
 * - With approval on, new images wait (`pending`) and only their owner, administrators and moderators see them.
 * - Size, extensions and the optional per-member total come from GalleryRules.
 * Files are written under storage/gallery with random keys before the row commits and removed if it does not.
 */
final readonly class Gallery
{
    public const VISIBILITIES = ['public', 'members', 'private'];

    public const PAGE = 48;

    /** Pending images: only their owner, administrators and moderators (`i.status`, `i.user_id`). */
    private const ITEM_SCOPE = "(i.status = 'visible' OR ? = 1 OR i.user_id = ?)";

    public function __construct(
        private Connection $db,
        private GalleryImages $images,
        private string $storage,
        private string $public,
    ) {
    }

    /**
     * @throws GalleryRejected `gallery_denied`, `gallery_title_invalid` or `gallery_visibility_invalid`.
     */
    public function saveAlbum(
        GalleryViewer $viewer,
        ?int $albumId,
        string $title,
        string $description,
        string $visibility,
    ): int {
        $owner = $viewer->id ?? throw new GalleryRejected('gallery_denied');
        $title = self::text($title, 100) ?? throw new GalleryRejected('gallery_title_invalid');
        $description = trim($description) === '' ? null
            : self::text($description, 500) ?? throw new GalleryRejected('gallery_description_invalid');

        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new GalleryRejected('gallery_visibility_invalid');
        }

        $now = self::now($this->db);

        if ($albumId === null) {
            $this->db->insert('gallery_albums', ['user_id' => $owner, 'title' => $title, 'description' => $description,
                'visibility' => $visibility, 'created_at' => $now, 'updated_at' => $now]);

            return (int) $this->db->lastInsertId();
        }

        $this->manageable($viewer, $albumId);
        $this->db->update('gallery_albums', ['title' => $title, 'description' => $description,
            'visibility' => $visibility, 'updated_at' => $now], ['id' => $albumId]);

        return $albumId;
    }

    /**
     * The member's album for images added while writing posts (2026-11-09): found by title, created public on
     * first use.
     */
    public function attachAlbum(GalleryViewer $viewer, string $title): int
    {
        $owner = $viewer->id ?? throw new GalleryRejected('gallery_denied');
        $id = $this->db->fetchOne(
            'SELECT id FROM gallery_albums WHERE user_id = ? AND title = ? ORDER BY id LIMIT 1',
            [$owner, $title],
        );

        return $id === false ? $this->saveAlbum($viewer, null, $title, '', 'public') : (int) $id;
    }

    /** Deletes the album, its images and their files. */
    public function deleteAlbum(GalleryViewer $viewer, int $albumId): void
    {
        $this->manageable($viewer, $albumId);
        $keys = $this->db->fetchAllAssociative(
            'SELECT file_key, thumb_key FROM gallery_items WHERE album_id = ?',
            [$albumId],
        );
        $this->db->delete('gallery_albums', ['id' => $albumId]);
        $this->audit($viewer, 'gallery.album_deleted', $albumId);

        foreach ($keys as $row) {
            $this->unlink($row['file_key']);
            $this->unlink($row['thumb_key']);
        }
    }

    /**
     * @return array<string, mixed>|null The album when the viewer may see it, with `manageable`.
     */
    public function album(GalleryViewer $viewer, int $albumId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.user_id, a.title, a.description, a.visibility, a.created_at, u.username '
                . 'FROM gallery_albums a INNER JOIN users u ON u.id = a.user_id WHERE a.id = ?',
            [$albumId],
        );

        if ($row === false || !self::sees($viewer, (string) $row['visibility'], (int) $row['user_id'])) {
            return null;
        }

        return $row + ['manageable' => $viewer->admin || $viewer->id === (int) $row['user_id']];
    }

    /**
     * Albums the viewer may see, newest first; with $owner only that member's.
     *
     * @return list<array<string, mixed>> id, user_id, username, title, visibility, items, cover (item id or null).
     */
    public function albums(GalleryViewer $viewer, ?int $owner = null, int $before = 0): array
    {
        [$where, $albumParameters] = self::albumScope($viewer);
        $items = self::itemScopeParameters($viewer);

        return $this->db->fetchAllAssociative(
            'SELECT a.id, a.user_id, u.username, a.title, a.visibility, '
                . '(SELECT COUNT(*) FROM gallery_items i WHERE i.album_id = a.id AND ' . self::ITEM_SCOPE
                . ') AS items, (SELECT MAX(i.id) FROM gallery_items i WHERE i.album_id = a.id AND '
                . self::ITEM_SCOPE . ') AS cover FROM gallery_albums a INNER JOIN users u ON u.id = a.user_id '
                . 'WHERE ' . $where . ' AND (? = 0 OR a.user_id = ?) AND (? = 0 OR a.id < ?) '
                . 'ORDER BY a.id DESC LIMIT ' . (self::PAGE + 1),
            [...$items, ...$items, ...$albumParameters, $owner ?? 0, $owner ?? 0, $before, $before],
        );
    }

    /**
     * Images of one album (newest first) or, with $albumId null, the newest images of every album the viewer sees.
     *
     * @return list<array<string, mixed>> id, album_id, user_id, username, title, width, height, status, created_at.
     */
    public function items(GalleryViewer $viewer, ?int $albumId, int $before = 0, bool $pendingOnly = false): array
    {
        [$where, $parameters] = self::albumScope($viewer);

        return $this->db->fetchAllAssociative(
            'SELECT i.id, i.album_id, i.user_id, u.username, i.title, i.width, i.height, i.status, i.created_at '
                . 'FROM gallery_items i INNER JOIN gallery_albums a ON a.id = i.album_id '
                . 'INNER JOIN users u ON u.id = i.user_id WHERE ' . $where . ' AND ' . self::ITEM_SCOPE
                . ' AND (? = 0 OR i.album_id = ?) AND (? = 0 OR i.id < ?)'
                . ($pendingOnly ? " AND i.status = 'pending'" : '')
                . ' ORDER BY i.id DESC LIMIT ' . (self::PAGE + 1),
            [...$parameters, ...self::itemScopeParameters($viewer), $albumId ?? 0, $albumId ?? 0, $before, $before],
        );
    }

    /**
     * @return array<string, mixed>|null One image the viewer may see, with album title/visibility and `manageable`.
     */
    public function item(GalleryViewer $viewer, int $itemId): ?array
    {
        [$where, $parameters] = self::albumScope($viewer);
        $row = $this->db->fetchAssociative(
            'SELECT i.id, i.album_id, i.user_id, u.username, i.title, i.mime, i.bytes, i.width, i.height, i.status, '
                . 'i.created_at, i.file_key, i.thumb_key, a.title AS album_title, a.visibility '
                . 'FROM gallery_items i INNER JOIN gallery_albums a ON a.id = i.album_id '
                . 'INNER JOIN users u ON u.id = i.user_id WHERE i.id = ? AND ' . $where . ' AND ' . self::ITEM_SCOPE,
            [$itemId, ...$parameters, ...self::itemScopeParameters($viewer)],
        );

        return $row === false ? null : $row + ['manageable' => $viewer->admin || $viewer->id === (int) $row['user_id']];
    }

    /**
     * Adds an image to one of the viewer's albums (administrators: any album).
     *
     * @return int The new image ID.
     *
     * @throws GalleryRejected `gallery_disabled`, `gallery_denied`, `gallery_upload_invalid`, `gallery_too_large`
     *     (`max`), `gallery_type_refused` (`types`), `gallery_quota` (`max`), `gallery_title_invalid` or
     *     `gallery_upload_failed`.
     */
    public function upload(
        GalleryViewer $viewer,
        int $albumId,
        UploadedFile $upload,
        string $title,
        GalleryRules $rules,
    ): int {
        if (!$rules->enabled) {
            throw new GalleryRejected('gallery_disabled');
        }

        $album = $this->manageable($viewer, $albumId);
        $megabytes = ['max' => intdiv($rules->maxBytes, 1048576)];

        if (!$upload->isValid() || $upload->getSize() < 1) {
            throw new GalleryRejected($upload->getError() === UPLOAD_ERR_INI_SIZE ? 'gallery_too_large'
                : 'gallery_upload_invalid', $megabytes);
        }

        if ($upload->getSize() > $rules->maxBytes) {
            throw new GalleryRejected('gallery_too_large', $megabytes);
        }

        $extension = strtolower(pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION));

        if (!in_array($extension, $rules->extensions, true)) {
            throw new GalleryRejected('gallery_type_refused', ['types' => implode(', ', $rules->extensions)]);
        }

        $bytes = file_get_contents($upload->getPathname(), false, null, 0, $rules->maxBytes + 1);

        if (!is_string($bytes) || strlen($bytes) > $rules->maxBytes || $bytes === '') {
            throw new GalleryRejected('gallery_upload_invalid');
        }

        $name = $title !== '' ? $title : pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);
        $title = self::text($name, 100) ?? self::text('#' . bin2hex(random_bytes(3)), 100);

        try {
            $image = $this->images->process($bytes, $rules->extensions);
        } catch (GalleryRejected $rejected) {
            throw $rejected->diagnostic === 'gallery_type_refused'
                ? new GalleryRejected('gallery_type_refused', ['types' => implode(', ', $rules->extensions)])
                : $rejected;
        }

        unset($bytes);
        $keys = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
        $written = [];

        try {
            foreach ([$keys[0] => $image['display'], $keys[1] => $image['thumb']] as $key => $data) {
                $this->write($key, $data);
                $written[] = $key;
            }

            return (int) $this->db->transactional(function (Connection $db) use (
                $viewer,
                $album,
                $rules,
                $title,
                $image,
                $keys,
            ): int {
                $owner = (int) $album['user_id'];
                $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$owner]);
                $size = strlen($image['display']) + strlen($image['thumb']);

                if (
                    $rules->quotaBytes > 0 && (int) $db->fetchOne(
                        'SELECT COALESCE(SUM(bytes), 0) FROM gallery_items WHERE user_id = ?',
                        [$owner],
                    ) + $size > $rules->quotaBytes
                ) {
                    throw new GalleryRejected('gallery_quota', ['max' => intdiv($rules->quotaBytes, 1048576)]);
                }

                $db->insert('gallery_items', ['album_id' => $album['id'], 'user_id' => $owner, 'title' => $title,
                    'file_key' => $keys[0], 'thumb_key' => $keys[1], 'mime' => $image['mime'], 'bytes' => $size,
                    'width' => $image['width'], 'height' => $image['height'],
                    'status' => $rules->approval && !$viewer->admin && !$viewer->moderator ? 'pending' : 'visible',
                    'created_at' => self::now($db)]);

                return (int) $db->lastInsertId();
            });
        } catch (Throwable $failure) {
            foreach ($written as $key) {
                $this->unlink($key);
            }

            throw $failure;
        }
    }

    public function deleteItem(GalleryViewer $viewer, int $itemId): int
    {
        $item = $this->item($viewer, $itemId);

        if ($item === null || !$item['manageable']) {
            throw new GalleryRejected('gallery_denied');
        }

        $this->db->delete('gallery_items', ['id' => $itemId]);
        $this->audit($viewer, 'gallery.item_deleted', $itemId);
        $this->unlink((string) $item['file_key']);
        $this->unlink((string) $item['thumb_key']);

        return (int) $item['album_id'];
    }

    /**
     * Removes files no row points to any more (2026-11-08): images of deleted accounts (their rows cascade) and
     * uploads whose row never committed. One of the 256 key buckets per call, rotating, and only files older than
     * an hour, so an upload in progress is never touched. Returns the number of removed files.
     */
    public function collect(int $bucket): int
    {
        $prefix = sprintf('%02x', $bucket % 256);
        $root = realpath($this->storage);
        $directory = $root === false ? null : $root . '/gallery/' . $prefix;

        if ($directory === null || !is_dir($directory) || is_link($directory)) {
            return 0;
        }

        $old = [];

        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory . '/' . $name;

            if (
                preg_match('/^[a-f0-9]{64}$/D', $name) === 1 && is_file($path) && !is_link($path)
                && (int) filemtime($path) < time() - 3600
            ) {
                $old[] = $name;
            }

            if (count($old) >= 500) {
                break;
            }
        }

        if ($old === []) {
            return 0;
        }

        $known = array_flip(array_merge(
            $this->db->fetchFirstColumn(
                'SELECT file_key FROM gallery_items WHERE file_key IN (?)',
                [$old],
                [ArrayParameterType::STRING]
            ),
            $this->db->fetchFirstColumn(
                'SELECT thumb_key FROM gallery_items WHERE thumb_key IN (?)',
                [$old],
                [ArrayParameterType::STRING]
            ),
        ));
        $removed = 0;

        foreach ($old as $key) {
            if (!isset($known[$key])) {
                $this->unlink($key);
                $removed++;
            }
        }

        return $removed;
    }

    /** Approves a pending image (administrators and moderators). */
    public function approve(GalleryViewer $viewer, int $itemId): void
    {
        if (!$viewer->admin && !$viewer->moderator) {
            throw new GalleryRejected('gallery_denied');
        }

        $changed = $this->db->update(
            'gallery_items',
            ['status' => 'visible'],
            ['id' => $itemId, 'status' => 'pending'],
        );

        if ($changed === 1) {
            $this->audit($viewer, 'gallery.item_approved', $itemId);
        }
    }

    /** Bytes the member's images use now. */
    public function usage(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(bytes), 0) FROM gallery_items WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * The image file (display copy or thumbnail) when the viewer may see the image.
     *
     * @throws GalleryRejected `gallery_not_found`.
     */
    public function file(GalleryViewer $viewer, int $itemId, bool $thumb): BinaryFileResponse
    {
        $item = $this->item($viewer, $itemId);
        $path = $item === null ? null : $this->path((string) ($thumb ? $item['thumb_key'] : $item['file_key']));

        if ($item === null || $path === null || !is_file($path) || is_link($path)) {
            throw new GalleryRejected('gallery_not_found');
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => (string) $item['mime'],
            // Public albums may be cached by the browser; nothing else leaves the private cache.
            'Cache-Control' => $item['visibility'] === 'public' && $item['status'] === 'visible'
                ? 'public, max-age=86400' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], public: false, autoEtag: false, autoLastModified: false);
        $response->setContentDisposition('inline', 'image', 'image');

        return $response;
    }

    /** @return list<int> */
    private static function itemScopeParameters(GalleryViewer $viewer): array
    {
        return [(int) ($viewer->admin || $viewer->moderator), $viewer->id ?? 0];
    }

    /** @return array{string, list<int>} */
    private static function albumScope(GalleryViewer $viewer): array
    {
        return ["(a.visibility = 'public' OR (a.visibility = 'members' AND ? = 1) OR a.user_id = ? OR ? = 1)",
            [(int) ($viewer->id !== null), $viewer->id ?? 0, (int) $viewer->admin]];
    }

    private static function sees(GalleryViewer $viewer, string $visibility, int $owner): bool
    {
        return $viewer->admin || $owner === $viewer->id || $visibility === 'public'
            || ($visibility === 'members' && $viewer->id !== null);
    }

    /** @return array<string, mixed> */
    private function manageable(GalleryViewer $viewer, int $albumId): array
    {
        $album = $this->album($viewer, $albumId);

        if ($album === null || !$album['manageable']) {
            throw new GalleryRejected('gallery_denied');
        }

        return $album;
    }

    private function audit(GalleryViewer $viewer, string $action, int $id): void
    {
        if ($viewer->admin && $viewer->id !== null) {
            $this->db->insert('audit_logs', ['actor_id' => $viewer->id, 'action' => $action,
                'target_type' => 'gallery', 'target_id' => $id, 'reason' => null, 'details' => '{}',
                'created_at' => self::now($this->db)]);
        }
    }

    /** Normalized single-line text, or null when it is empty or longer than $max characters. */
    private static function text(string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($value, 'UTF-8') ? $value : ''));
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }

    private static function now(Connection $db): string
    {
        return (string) $db->fetchOne('SELECT UTC_TIMESTAMP(6)');
    }

    private function write(string $key, string $data): void
    {
        $path = $this->path($key, true) ?? throw new GalleryRejected('gallery_upload_failed');
        $stream = fopen($path, 'xb');

        if ($stream === false) {
            throw new GalleryRejected('gallery_upload_failed');
        }

        try {
            chmod($path, 0600);

            if (fwrite($stream, $data) !== strlen($data) || !fflush($stream)) {
                throw new GalleryRejected('gallery_upload_failed');
            }
        } finally {
            fclose($stream);
        }
    }

    private function unlink(string $key): void
    {
        $path = $this->path($key);

        if ($path !== null && is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    /** storage/gallery/<2 hex>/<64 hex>; never inside the public directory. */
    private function path(string $key, bool $create = false): ?string
    {
        $root = realpath($this->storage);
        $public = realpath($this->public);
        $normalize = static fn(string $value): string => strtolower(str_replace('\\', '/', $value));

        if (
            preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $root === false || $public === false
            || str_starts_with($normalize($root) . '/', $normalize($public) . '/')
        ) {
            return null;
        }

        $directory = $root . '/gallery';

        foreach ([$directory, $directory . '/' . substr($key, 0, 2)] as $part) {
            if (is_link($part) || (!is_dir($part) && (!$create || !mkdir($part, 0700)))) {
                return null;
            }
        }

        return $directory . '/' . substr($key, 0, 2) . '/' . $key;
    }
}
