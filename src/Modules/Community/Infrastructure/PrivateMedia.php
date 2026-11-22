<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use finfo;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/** Private random-key storage. User-supplied paths, URLs, MIME and filename extensions are never trusted. */
final class PrivateMedia
{
    /**
     * Attachment types an administrator may allow (beta.18 roadmap 15): a fixed, safe catalog. Images are re-encoded
     * by ImageProcessor; every other type is served as a download with `nosniff`, never inline.
     */
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'zip' => 'application/zip', '7z' => 'application/x-7z-compressed',
        'gz' => 'application/gzip'];

    /** Types allowed while Admin > Site settings leaves the list empty (the behaviour before beta.18). */
    public const DEFAULT_TYPES = ['png', 'jpg', 'pdf', 'txt'];

    public const MAX_ATTACHMENT_BYTES = 20971520;

    /**
     * @param (Closure(): array{types: list<string>, bytes: int})|null $policy Allowed type keys and the size limit
     *     of post attachments; null keeps DEFAULT_TYPES and ImageProcessor::MAX_BYTES.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
        private readonly MemberProfiles $profiles,
        private readonly ImageProcessor $images,
        private readonly string $storage,
        private readonly string $public,
        private readonly ?Closure $policy = null,
    ) {
    }

    /** @return array{types: list<string>, bytes: int} */
    public function policy(): array
    {
        $policy = $this->policy === null ? [] : ($this->policy)();
        $types = array_values(array_intersect($policy['types'] ?? [], array_keys(self::TYPES)));

        return ['types' => $types === [] ? self::DEFAULT_TYPES : $types,
            'bytes' => max(1, min((int) ($policy['bytes'] ?? ImageProcessor::MAX_BYTES), self::MAX_ATTACHMENT_BYTES))];
    }

    public function upload(SubjectPermissions $subject, UploadedFile $upload, ?int $postId, bool $avatar): string
    {
        $actor = $this->access->actor($subject);

        $policy = $this->policy();
        $allowed = array_map(static fn(string $type): string => self::TYPES[$type], $policy['types']);
        $limit = $avatar ? ImageProcessor::MAX_BYTES : $policy['bytes'];

        if (!$upload->isValid() || $upload->getSize() < 1 || $upload->getSize() > $limit) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $bytes = file_get_contents($upload->getPathname(), false, null, 0, $limit + 1);

        if (!is_string($bytes) || strlen($bytes) > $limit) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $result = ['bytes' => $bytes, 'thumbnail' => null, 'width' => null, 'height' => null, 'mime' => $mime];

        if ($avatar || in_array($mime, ['image/png', 'image/jpeg'], true)) {
            if (!$avatar && (!in_array($mime, $allowed, true) || strlen($bytes) > ImageProcessor::MAX_BYTES)) {
                throw new RegistrationRejected(['community_upload_invalid']);
            }

            $result = $this->images->process($bytes, $avatar);
        } elseif (
            !in_array($mime, $allowed, true)
            || ($mime === 'text/plain' && (!mb_check_encoding($bytes, 'UTF-8')
                || str_contains(strtolower($bytes), '<?php') || str_contains($bytes, "\0")))
        ) {
            throw new RegistrationRejected(['community_upload_invalid']);
        }

        $id = bin2hex(random_bytes(16));
        $key = bin2hex(random_bytes(32));
        $thumb = $result['thumbnail'] === null ? null : bin2hex(random_bytes(32));
        $name = $avatar ? 'avatar' : CommunityAccess::text($upload->getClientOriginalName(), 160);
        $name = preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $name) ?: 'attachment';
        $paths = [];

        try {
            $variants = [$key => $result['bytes'], ...($thumb === null ? [] : [$thumb => $result['thumbnail']])];

            foreach ($variants as $k => $data) {
                $path = $this->path($k);
                $stream = fopen($path, 'xb');

                if ($stream === false) {
                    throw new RegistrationRejected(['community_upload_failed']);
                }

                $paths[] = $path;

                try {
                    chmod($path, 0600);

                    if (fwrite($stream, $data) !== strlen($data) || !fflush($stream)) {
                        throw new RegistrationRejected(['community_upload_failed']);
                    }
                } finally {
                    fclose($stream);
                }
            }

            $this->db->transactional(function (Connection $db) use (
                $subject,
                $actor,
                $postId,
                $avatar,
                $id,
                $key,
                $thumb,
                $result,
                $name,
            ): void {
                $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor]);
                $total = $db->fetchAssociative('SELECT COUNT(*) AS items, COALESCE(SUM(bytes), 0) AS bytes '
                    . 'FROM community_media WHERE user_id = ? AND deleted_at IS NULL', [$actor]);

                if ((int) $total['items'] >= 100 || (int) $total['bytes'] + strlen($result['bytes']) > 52428800) {
                    throw new RegistrationRejected(['community_upload_limit']);
                }

                if (!$avatar) {
                    $post = $this->access->post($subject, $postId ?? 0, true);
                    $thread = $this->access->thread($subject, (int) $post['thread_id'], true);
                    $forum = (int) $thread['forum_id'];
                    $moderator = $subject->allows(EditPost::EDIT_ANY, $forum);
                    $created = (new DateTimeImmutable($post['created_at'], new DateTimeZone('UTC')))->getTimestamp();
                    $window = (int) $subject->value(EditPost::WINDOW, $forum);

                    if (
                        !$moderator && (!$subject->allows(EditPost::EDIT_OWN, $forum)
                        || (int) $thread['is_locked'] === 1 || ($window > 0 && time() >= $created + $window * 60))
                    ) {
                        throw new RegistrationRejected(['community_denied']);
                    }


                    if (
                        (int) $post['user_id'] !== $actor
                        || (int) $db->fetchOne('SELECT COUNT(*) FROM community_media '
                            . 'WHERE post_id = ? AND deleted_at IS NULL', [$postId]) >= 5
                    ) {
                        throw new RegistrationRejected(['community_denied']);
                    }
                } else {
                    $db->executeStatement('UPDATE community_media SET deleted_at = CURRENT_TIMESTAMP(6), '
                        . 'avatar_for = NULL WHERE avatar_for = ?', [$actor]);
                }

                $db->insert('community_media', ['id' => $id, 'user_id' => $actor,
                    'post_id' => $avatar ? null : $postId, 'avatar_for' => $avatar ? $actor : null,
                    'file_key' => $key, 'thumb_key' => $thumb, 'original_name' => $name, 'mime' => $result['mime'],
                    'bytes' => strlen($result['bytes']), 'width' => $result['width'], 'height' => $result['height'],
                    'created_at' => CommunityInteractions::now()]);
            });
        } catch (Throwable $failure) {
            foreach ($paths as $path) {
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }

            throw $failure;
        }

        return $id;
    }

    public function remove(SubjectPermissions $subject, string $id): void
    {
        $actor = $this->access->actor($subject);
        $this->db->transactional(function (Connection $db) use ($subject, $actor, $id): void {
            $row = $db->fetchAssociative(
                'SELECT id, user_id, avatar_for FROM community_media WHERE id = ? FOR UPDATE',
                [$id]
            );

            if (
                $row === false || ((int) $row['user_id'] !== $actor
                && !($row['avatar_for'] !== null && $subject->allows('moderation.profile.manage')))
            ) {
                throw new RegistrationRejected(['community_not_found']);
            }

            $db->update(
                'community_media',
                ['deleted_at' => CommunityInteractions::now(), 'avatar_for' => null],
                ['id' => $id]
            );

            if ((int) $row['user_id'] !== $actor) {
                $db->insert('audit_logs', ['actor_id' => $actor, 'action' => 'avatar.reset', 'target_type' => 'user',
                    'target_id' => $row['user_id'], 'created_at' => CommunityInteractions::now()]);
            }
        });
    }

    public function resetAvatar(SubjectPermissions $subject, int $user): void
    {
        if (!$subject->allows('moderation.profile.manage')) {
            throw new RegistrationRejected(['community_denied']);
        }

        $id = $this->db->fetchOne('SELECT id FROM community_media WHERE avatar_for = ? '
            . 'AND deleted_at IS NULL LIMIT 1', [$user]);

        if ($id !== false) {
            $this->remove($subject, (string) $id);
        }
    }

    public function download(SubjectPermissions $subject, string $id, bool $thumbnail): BinaryFileResponse
    {
        $row = $this->db->fetchAssociative('SELECT id, user_id, post_id, avatar_for, file_key, thumb_key, '
            . 'COALESCE(imported_name, original_name) AS original_name, mime, imported_visibility '
            . 'FROM community_media WHERE id = ? '
            . 'AND (user_id IS NOT NULL OR imported_retained = 1) AND deleted_at IS NULL', [$id]);

        if ($row === false) {
            throw new RegistrationRejected(['community_not_found']);
        }

        if ($row['avatar_for'] !== null) {
            if ($this->profiles->find($subject, (int) $row['avatar_for']) === null) {
                throw new RegistrationRejected(['community_not_found']);
            }
        } elseif ($row['post_id'] !== null) {
            $post = $this->access->post($subject, (int) $row['post_id']);
            if (
                $row['imported_visibility'] !== null && $row['imported_visibility'] !== 'visible'
                && (int) $row['user_id'] !== $subject->userId
                && !$subject->allows('moderation.post.edit_any', (int) $post['forum_id'])
            ) {
                throw new RegistrationRejected(['community_not_found']);
            }
        } else {
            throw new RegistrationRejected(['community_not_found']);
        }

        $key = $thumbnail && $row['thumb_key'] !== null ? $row['thumb_key'] : $row['file_key'];
        $path = $this->path($key);

        if (!is_file($path) || is_link($path)) {
            throw new RegistrationRejected(['community_not_found']);
        }

        $response = new BinaryFileResponse(
            $path,
            200,
            ['Content-Type' => $row['mime'],
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Robots-Tag' => 'noindex'],
            public: false,
            autoEtag: false,
            autoLastModified: false
        );
        $inline = str_starts_with($row['mime'], 'image/');
        $response->setContentDisposition(
            $inline ? 'inline' : 'attachment',
            $inline ? 'image' : str_replace(['/', '\\'], '_', (string) $row['original_name']),
            $inline ? 'image' : 'attachment'
        );

        return $response;
    }

    public function collect(): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, file_key, thumb_key FROM community_media '
            . 'WHERE deleted_at IS NOT NULL OR (user_id IS NULL AND imported_retained = 0) '
            . 'OR (post_id IS NULL AND avatar_for IS NULL) LIMIT 10');

        foreach ($rows as $row) {
            foreach ([$row['file_key'], $row['thumb_key']] as $key) {
                if ($key === null) {
                    continue;
                }

                $path = $this->path($key);

                if (is_link($path) || (is_file($path) && !unlink($path))) {
                    throw new RegistrationRejected(['community_upload_failed']);
                }
            }

            $this->db->delete('community_media', ['id' => $row['id']]);
        }
    }

    public function path(string $key): string
    {
        $root = realpath($this->storage);
        $public = realpath($this->public);
        $normalize = static fn(string $value): string => strtolower(str_replace('\\', '/', $value));

        if (
            preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $root === false || $public === false
            || str_starts_with($normalize($root) . '/', $normalize($public) . '/')
        ) {
            throw new RegistrationRejected(['community_upload_failed']);
        }

        $directory = $root . '/community-media';

        foreach ([$directory, $directory . '/' . substr($key, 0, 2)] as $part) {
            if (is_link($part) || (!is_dir($part) && !mkdir($part, 0700))) {
                throw new RegistrationRejected(['community_upload_failed']);
            }
        }

        return $directory . '/' . substr($key, 0, 2) . '/' . $key;
    }
}
