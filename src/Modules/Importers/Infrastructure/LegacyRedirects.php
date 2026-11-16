<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;

/** Redirect lookup enforces current target visibility, never the old site's permissions or a cached public URL. */
final readonly class LegacyRedirects
{
    public function __construct(
        private Connection $db,
        private ForumVisibility $visibility,
        private UrlGenerator $urls
    ) {
    }

    public function engine(string $job): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) {
            return null;
        }
        $engine = $this->db->fetchOne(
            'SELECT engine FROM import_jobs WHERE id = ? AND state = ?',
            [$job, 'completed']
        );
        return is_string($engine) ? $engine : null;
    }

    public function target(string $job, string $entity, int $source, SubjectPermissions $subject): ?string
    {
        if (
            $source < 1 || !in_array($entity, ['users', 'forums', 'threads', 'posts', 'attachments'], true)
            || $this->engine($job) === null
        ) {
            return null;
        }
        $target = $this->db->fetchOne('SELECT target_id FROM legacy_redirects WHERE job_id = ? AND '
            . 'entity = ? '
            . 'AND source_id = ?', [$job, $entity, $source]);
        if (!is_string($target)) {
            return null;
        }
        if ($entity === 'users') {
            $access = new CommunityAccess($this->db, $this->visibility);
            $user = (new MemberProfiles($this->db, $access))->find($subject, (int) $target);
            return $user !== null
                ? $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'profile', 'user'
                    => (int) $target]) : null;
        }
        if ($entity === 'forums') {
            return $this->visibility->visible($subject)->contains((int) $target)
                ? $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (int) $target]) : null;
        }
        $row = match ($entity) {
            'threads' => $this->db->fetchAssociative('SELECT forum_id, status, moved_to_thread_id, slug FROM threads '
                . 'WHERE id = ? AND deleted_at IS NULL', [(int) $target]),
            'posts' => $this->db->fetchAssociative('SELECT t.forum_id, t.status, t.moved_to_thread_id, '
                . 'p.status AS post_status FROM posts p INNER JOIN threads t ON t.id = p.thread_id '
                . 'WHERE p.id = ? AND p.deleted_at IS NULL AND t.deleted_at IS NULL', [(int) $target]),
            'attachments' => $this->db->fetchAssociative('SELECT t.forum_id, t.status, t.moved_to_thread_id, '
                . 'p.status AS post_status, m.user_id, m.imported_visibility FROM community_media m '
                . 'INNER JOIN posts p ON p.id = m.post_id INNER JOIN threads t ON t.id = p.thread_id '
                . 'WHERE m.id = ? AND m.deleted_at IS NULL AND p.deleted_at IS NULL '
                    . 'AND t.deleted_at IS NULL', [$target]),
        };
        if (
            $row === false || $row['status'] !== 'visible'
                || ($row['post_status'] ?? 'visible') !== 'visible'
            || !$this->visibility->readable($subject)->contains((int) $row['forum_id'])
        ) {
            return null;
        }
        if (
            $entity === 'attachments' && $row['imported_visibility'] !== 'visible'
            && ($subject->userId === null || $subject->userId !== (int) $row['user_id'])
            && !$subject->allows('moderation.post.edit_any', (int) $row['forum_id'])
        ) {
            return null;
        }
        if ($row['moved_to_thread_id'] !== null) {
            $moved = $this->db->fetchAssociative(
                'SELECT forum_id, status FROM threads WHERE id = ? AND deleted_at IS NULL',
                [(int) $row['moved_to_thread_id']]
            );
            if (
                $moved === false || $moved['status'] !== 'visible'
                || !$this->visibility->readable($subject)->contains((int) $moved['forum_id'])
            ) {
                return null;
            }
        }
        return match ($entity) {
            // One hop to the canonical address; a moved thread's stub redirects once more to its target.
            'threads' => $row['moved_to_thread_id'] === null
                ? $this->urls->generate(
                    CoreRoutes::THREAD_SHOW,
                    ['slug' => (string) $row['slug'], 'thread' => (int) $target],
                )
                : $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (int) $target]),
            'posts' => $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (int) $target]),
            'attachments' => $this->urls->generate(CoreRoutes::COMMUNITY_MEDIA, ['media' => $target]),
        };
    }
}
