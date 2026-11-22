<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Modules\Community\Domain\Mentions;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Throwable;

/** Durable inbox, reauthorized before LIMIT. Only identifiers are stored, never content snapshots. */
final class CommunityNotifications
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
        private readonly CommunityInteractions $interactions,
    ) {
    }

    public const UNREAD_CAP = 10;

    public function page(SubjectPermissions $subject, int $before = 0): array
    {
        $actor = $this->access->actor($subject);
        [$visible, $params, $types] = $this->visible($subject);

        return $this->db->fetchAllAssociative(
            'SELECT n.id, n.kind, n.thread_id, n.post_id, n.actor_id, n.read_at, n.created_at ' . $visible
                . 'AND (? = 0 OR n.id < ?) ORDER BY n.id DESC LIMIT 26',
            [$actor, ...$params, $before, $before],
            [ParameterType::INTEGER, ...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * Header badge: the same reauthorized inbox filter as page(), counted only up to UNREAD_CAP so the query stays
     * bounded (ix_notifications_unread) however large the backlog is.
     */
    public function unreadCount(SubjectPermissions $subject): int
    {
        $actor = $this->access->actor($subject);
        [$visible, $params, $types] = $this->visible($subject);

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM (SELECT n.id ' . $visible . 'AND n.read_at IS NULL LIMIT '
                . self::UNREAD_CAP . ') unread',
            [$actor, ...$params],
            [ParameterType::INTEGER, ...$types],
        );
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: list<mixed>} FROM/WHERE ending in a space; first parameter is the
     *     actor, supplied by the caller.
     */
    private function visible(SubjectPermissions $subject): array
    {
        [$where, $params, $types] = $this->access->scope($subject, 't.forum_id');

        return [
            'FROM member_notifications n LEFT JOIN threads t ON t.id = n.thread_id '
                . 'LEFT JOIN posts p ON p.id = n.post_id LEFT JOIN member_profiles profile '
                . 'ON profile.user_id = n.actor_id WHERE n.user_id = ? '
                . 'AND NOT EXISTS (SELECT 1 FROM member_blocks b WHERE '
                . '(b.user_id = n.user_id AND b.target_id = n.actor_id) '
                . 'OR (b.target_id = n.user_id AND b.user_id = n.actor_id)) AND ('
                . "(n.kind = 'follow' AND ? = 1 AND (profile.user_id IS NULL "
                . "OR profile.visibility IN ('everyone', 'members'))) OR (n.thread_id IS NOT NULL "
                . "AND t.status = 'visible' AND t.deleted_at IS NULL AND t.moved_to_thread_id IS NULL AND " . $where
                . " AND (n.post_id IS NULL OR (p.status = 'visible' AND p.deleted_at IS NULL "
                . 'AND p.thread_id = n.thread_id)))) ',
            [(int) $subject->allows('user.profile.view'), ...$params],
            [ParameterType::INTEGER, ...$types],
        ];
    }

    public function read(SubjectPermissions $subject, int $id): void
    {
        $actor = $this->access->actor($subject);
        $this->db->executeStatement('UPDATE member_notifications SET read_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, $actor]);
    }

    public function fanout(OutboxLease $lease, Closure $guard): bool
    {
        $guard();
        $payload = json_decode($lease->payload, true, 8, JSON_THROW_ON_ERROR);
        $postId = $payload['post_id'] ?? null;
        $threadId = $payload['thread_id'] ?? null;
        $actor = $payload['user_id'] ?? null;

        if (in_array($lease->type, ['post.approved', 'thread.approved'], true)) {
            if (($payload['previous_status'] ?? '') !== 'pending') {
                return true;
            }

            $approvedPost = $lease->type === 'post.approved' ? $lease->aggregateId
                : (int) $this->db->fetchOne('SELECT first_post_id FROM threads WHERE id = ?', [$lease->aggregateId]);
            $publication = $this->db->fetchAssociative(
                'SELECT id, thread_id, user_id FROM posts WHERE id = ?',
                [$approvedPost]
            );

            if ($publication === false || $publication['user_id'] === null) {
                return true;
            }

            $postId = (int) $publication['id'];
            $threadId = (int) $publication['thread_id'];
            $actor = (int) $publication['user_id'];
            $payload['status'] = 'visible';
        }


        if (
            !is_int($postId) || !is_int($threadId) || !is_int($actor)
            || $actor < 1 || ($payload['status'] ?? '') !== 'visible'
        ) {
            return true;
        }

        $post = $this->db->fetchAssociative('SELECT p.id, p.created_at FROM posts p INNER JOIN threads t '
            . "ON t.id = p.thread_id WHERE p.id = ? AND t.id = ? AND p.status = 'visible' "
            . "AND t.status = 'visible' AND p.deleted_at IS NULL AND t.deleted_at IS NULL", [$postId, $threadId]);

        if ($post === false) {
            return true;
        }

        $this->db->executeStatement(
            SqlDialect::insertIgnore($this->db, 'INSERT INTO community_fanout (event_id, cursor_id) VALUES (?, 0)'),
            [$lease->id]
        );
        $eventAt = $this->db->fetchOne('SELECT created_at FROM outbox_events WHERE id = ?', [$lease->id]);
        $cutoff = is_string($eventAt) ? $eventAt : $post['created_at'];
        $cursor = (int) $this->db->fetchOne(
            'SELECT cursor_id FROM community_fanout WHERE event_id = ? FOR UPDATE',
            [$lease->id]
        );

        if ($cursor === 0) {
            $this->mentions($postId, $threadId, $actor);
        }
        // Push the cursor and per-source bound inside UNION; never materialize an entire follower list.
        $rows = $this->db->fetchFirstColumn(
            'SELECT candidates.user_id FROM ('
            . '(SELECT s.user_id FROM thread_subscriptions s INNER JOIN users u ON u.id = s.user_id '
            . "WHERE s.thread_id = ? AND s.created_at <= ? AND s.user_id > ? AND s.user_id <> ? "
            . "AND u.status = 'active' ORDER BY s.user_id LIMIT 26) UNION "
            . '(SELECT f.user_id FROM member_follows f INNER JOIN users u ON u.id = f.user_id '
            . 'WHERE f.target_id = ? AND f.created_at <= ? AND f.user_id > ? AND f.user_id <> ? '
            . "AND u.status = 'active' ORDER BY f.user_id LIMIT 26)) candidates "
            . 'ORDER BY candidates.user_id LIMIT 26',
            [$threadId, $cutoff, $cursor, $actor, $actor, $cutoff, $cursor, $actor]
        );

        // beta.18 roadmap 01: a new thread also reaches the members subscribed to its forum. Readability is checked
        // when the inbox is read, so a member who lost access never sees it.
        $forumRows = [];

        if (in_array($lease->type, ['thread.created', 'thread.approved'], true)) {
            try {
                $forumRows = $this->db->fetchFirstColumn('SELECT s.user_id FROM forum_subscriptions s '
                    . 'INNER JOIN threads t ON t.forum_id = s.forum_id INNER JOIN users u ON u.id = s.user_id '
                    . 'WHERE t.id = ? AND s.created_at <= ? AND s.user_id > ? AND s.user_id <> ? '
                    . "AND u.status = 'active' "
                    . 'ORDER BY s.user_id LIMIT 26', [$threadId, $cutoff, $cursor, $actor]);
            } catch (Throwable) {
                $forumRows = [];
            }
        }

        $kinds = [];

        foreach ($rows as $recipient) {
            $kinds[(int) $recipient] = 'reply';
        }

        foreach ($forumRows as $recipient) {
            $kinds[(int) $recipient] ??= 'forum_thread';
        }

        ksort($kinds);
        $rows = array_keys($kinds);

        foreach (array_slice($rows, 0, 25) as $recipient) {
            $guard();
            $this->interactions->notify(
                (int) $recipient,
                $actor,
                $kinds[$recipient],
                $threadId,
                $postId,
                'event:' . $lease->id,
            );
            $cursor = (int) $recipient;
        }

        $this->db->update('community_fanout', ['cursor_id' => $cursor], ['event_id' => $lease->id]);

        return count($rows) <= 25;
    }

    /**
     * @mentions of a newly visible post (2026-11-08): each named active member gets a `mention` notification once
     * per post (dedupe key), unless blocked or opted out of reply notices. Readability is checked when the inbox
     * is read, like every other notice.
     */
    private function mentions(int $postId, int $threadId, int $actor): void
    {
        $content = $this->db->fetchOne('SELECT content FROM posts WHERE id = ?', [$postId]);
        $names = is_string($content) ? Mentions::names($content) : [];

        if ($names === []) {
            return;
        }

        $recipients = $this->db->fetchFirstColumn(
            "SELECT id FROM users WHERE username_normalized IN (?) AND status = 'active'",
            [array_values(array_unique(array_map(IdentityNormalizer::username(...), $names)))],
            [ArrayParameterType::STRING],
        );

        foreach ($recipients as $recipient) {
            $this->interactions->notify((int) $recipient, $actor, 'mention', $threadId, $postId, 'mention:' . $postId);
        }
    }
}
