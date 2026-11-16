<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Actor-owned relationships and durable reactions. Lock the actor to serialize duplicate submissions. */
final class CommunityInteractions
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
        private readonly MemberProfiles $profiles,
    ) {
    }

    public function relationship(SubjectPermissions $subject, string $kind, int $target, bool $enable): void
    {
        $actor = $this->access->actor($subject);
        $tables = ['block' => 'member_blocks', 'follow' => 'member_follows',
            'bookmark' => 'member_bookmarks', 'subscribe' => 'thread_subscriptions'];

        if (!isset($tables[$kind])) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->transactional(function (Connection $db) use (
            $actor,
            $subject,
            $kind,
            $target,
            $enable,
            $tables,
        ): void {
            $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor]);
            $member = in_array($kind, ['block', 'follow'], true);
            $column = $member ? 'target_id' : 'thread_id';

            if ($member) {
                if (
                    $actor === $target || $db->fetchOne(
                        "SELECT id FROM users WHERE id = ? AND status = 'active'",
                        [$target],
                    ) === false || ($kind === 'follow' && $enable
                    && $this->profiles->find($subject, $target) === null)
                ) {
                    throw new RegistrationRejected(['community_not_found']);
                }
            } else {
                $this->access->thread($subject, $target, true);
            }

            $old = $db->fetchOne('SELECT user_id FROM ' . $tables[$kind]
                . ' WHERE user_id = ? AND ' . $column . ' = ?', [$actor, $target]);
            $db->delete($tables[$kind], ['user_id' => $actor, $column => $target]);

            if (
                $enable && $member && (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $tables[$kind]
                . ' WHERE user_id = ?', [$actor]) >= 200
            ) {
                throw new RegistrationRejected(['community_invalid']);
            }

            if ($enable) {
                $db->insert($tables[$kind], ['user_id' => $actor, $column => $target,
                    'created_at' => self::now()]);
            }

            if ($kind === 'follow' && $enable && $old === false && !$this->access->blocked($actor, $target)) {
                $this->notify($target, $actor, 'follow', null, null, 'follow:' . $actor . ':' . $target);
            }

            if ($kind === 'block' && $enable) {
                $db->executeStatement(
                    'DELETE FROM member_follows WHERE '
                    . '(user_id = ? AND target_id = ?) OR (user_id = ? AND target_id = ?)',
                    [$actor, $target, $target, $actor]
                );
            }
        });
    }

    public function react(SubjectPermissions $subject, int $postId, string $type): void
    {
        $actor = $this->access->actor($subject);

        if (!in_array($type, ['', 'like', 'thanks', 'insightful'], true)) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->transactional(function (Connection $db) use ($subject, $actor, $postId, $type): void {
            $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor]);
            $post = $this->access->post($subject, $postId, true);
            $target = (int) $post['user_id'];

            if ($target === $actor || ($target > 0 && $this->access->blocked($actor, $target))) {
                throw new RegistrationRejected(['community_denied']);
            }

            $db->delete('post_reactions', ['user_id' => $actor, 'post_id' => $postId]);

            if ($type !== '') {
                $db->insert('post_reactions', ['user_id' => $actor, 'post_id' => $postId,
                    'type_id' => $type, 'created_at' => self::now()]);

                if ($target > 0) {
                    $this->notify(
                        $target,
                        $actor,
                        'reaction',
                        (int) $post['thread_id'],
                        $postId,
                        'reaction:' . $actor . ':' . $postId
                    );
                }
            }
        });
    }

    public function notify(int $recipient, int $actor, string $kind, ?int $thread, ?int $post, string $key): void
    {
        if (!$this->db->isTransactionActive() || $recipient === $actor || $this->access->blocked($recipient, $actor)) {
            return;
        }

        $column = match ($kind) {
            'follow' => 'notify_follows',
            'reaction' => 'notify_reactions',
            default => 'notify_replies',
        };
        $preference = $this->db->fetchOne(
            'SELECT ' . $column . ' FROM member_profiles WHERE user_id = ?',
            [$recipient]
        );

        if ($preference !== false && (int) $preference !== 1) {
            return;
        }

        $changed = $this->db->executeStatement(
            SqlDialect::insertIgnore($this->db, 'INSERT INTO member_notifications '
                . '(user_id, actor_id, kind, thread_id, post_id, dedupe_key, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, utc_timestamp(6))'),
            [$recipient, $actor, $kind, $thread, $post, $key],
        );

        if ($changed === 1) {
            $id = (int) $this->db->lastInsertId();
            (new Outbox($this->db))->record(
                'community.mail',
                'notification',
                $id,
                ['notification_id' => $id],
                'community.mail:' . $id
            );
        }
    }

    public function bookmarks(SubjectPermissions $subject, int $before): array
    {
        $actor = $this->access->actor($subject);
        [$where, $params, $types] = $this->access->scope($subject, 't.forum_id');

        return $this->db->fetchAllAssociative(
            'SELECT t.id, t.title, t.slug FROM member_bookmarks b INNER JOIN threads t ON t.id = b.thread_id '
                . "WHERE b.user_id = ? AND t.status = 'visible' AND t.deleted_at IS NULL "
                . 'AND t.moved_to_thread_id IS NULL AND (? = 0 OR t.id < ?) AND ' . $where
                . ' ORDER BY t.id DESC LIMIT 26',
            [$actor, $before, $before, ...$params],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ...$types],
        );
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
