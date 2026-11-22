<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;

/** Bounded community page projections. Post extras are outside shared cached post fragments. */
final class CommunityRead
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
        private readonly MemberProfiles $profiles,
    ) {
    }

    public function thread(SubjectPermissions $subject, int $id, int $before): array
    {
        $tools = $this->threadTools($subject, $id);
        $posts = $this->db->fetchAllAssociative(
            'SELECT id, user_id FROM posts WHERE thread_id = ? '
            . "AND status = 'visible' AND deleted_at IS NULL AND (? = 0 OR id < ?) ORDER BY id DESC LIMIT 21",
            [$id, $before, $before]
        );
        $more = count($posts) > 20;
        $posts = array_slice($posts, 0, 20);

        return $tools + ['posts' => $posts, 'extras' => $this->extras($subject, array_column($posts, 'id')),
            'more' => $more, 'cursor' => $posts === [] ? 0 : (int) end($posts)['id']];
    }

    /**
     * Thread-level community state shown on the thread page itself (design pass 2026-11-07): poll with options
     * (vote counts hidden while results are hidden), the reader's bookmark/subscription/ballot, the accepted
     * answer and whether the reader may manage the thread. Same access check as the tools page.
     *
     * @throws RegistrationRejected community_not_found when the thread is not readable or not visible.
     */
    public function threadTools(SubjectPermissions $subject, int $id): array
    {
        $thread = $this->access->thread($subject, $id);
        $poll = $this->db->fetchAssociative('SELECT thread_id, user_id, COALESCE(imported_question, '
            . 'question) AS question, multiple_choice, '
            . 'closes_at, imported_lock, imported_results, imported_max_choices, allow_change FROM '
            . 'thread_polls WHERE thread_id = ?', [$id]);
        $options = $poll === false ? [] : $this->db->fetchAllAssociative(
            'SELECT o.id, COALESCE(o.imported_label, o.label) AS label, '
                . 'COUNT(v.user_id) + o.imported_votes AS votes FROM poll_options o LEFT JOIN poll_votes v '
                . 'ON v.thread_id = o.thread_id AND v.option_id = o.id WHERE o.thread_id = ? '
                . 'GROUP BY o.id, o.label, o.imported_label, o.imported_votes, o.position ORDER BY o.position',
            [$id],
        );
        $actor = $subject->userId ?? 0;
        $relationships = $this->db->fetchAssociative(
            'SELECT '
            . 'EXISTS (SELECT 1 FROM member_bookmarks WHERE user_id = ? AND thread_id = ?) AS bookmarked, '
            . 'EXISTS (SELECT 1 FROM thread_subscriptions WHERE user_id = ? AND thread_id = ?) AS subscribed, '
            . 'EXISTS (SELECT 1 FROM poll_ballots WHERE user_id = ? AND thread_id = ?) AS voted',
            [$actor, $id, $actor, $id, $actor, $id]
        );
        // Accepted answers belong to question-and-answer forums; elsewhere a stored one stays hidden (2026-11-14).
        $questions = (int) $this->db->fetchOne(
            'SELECT is_questions FROM forums WHERE id = ?',
            [(int) $thread['forum_id']],
        ) === 1;
        $solution = !$questions ? false : $this->db->fetchOne('SELECT s.post_id FROM thread_solutions s '
            . 'INNER JOIN posts p ON p.id = s.post_id WHERE s.thread_id = ? AND p.thread_id = ? '
            . "AND p.status = 'visible' "
            . 'AND p.deleted_at IS NULL', [$id, $id]);

        $resultsVisible = $poll === false || (int) $poll['imported_results'] === 0
            || ((int) $poll['imported_results'] === 1 && (int) $relationships['voted'] === 1)
            || ((int) $poll['imported_results'] === 2 && ((int) $poll['imported_lock'] === 1
                || ($poll['closes_at'] !== null && $poll['closes_at'] <= gmdate('Y-m-d H:i:s.u'))));
        if (!$resultsVisible) {
            foreach ($options as &$option) {
                $option['votes'] = 0;
            }
            unset($option);
        }
        return ['poll_results_visible' => $resultsVisible, 'thread' => $thread, 'poll'
            => $poll === false ? null : $poll, 'options' => $options,
            'poll_closed' => $poll !== false && ((int) $poll['imported_lock'] === 1
                || ($poll['closes_at'] !== null && $poll['closes_at'] <= gmdate('Y-m-d H:i:s.u'))),
            'relationship' => $relationships, 'solution' => $solution === false ? null : (int) $solution,
            'questions' => $questions,
            'can_manage' => $actor > 0 && ((int) $thread['user_id'] === $actor
                || $subject->allows('moderation.post.edit_any', (int) $thread['forum_id']))];
    }

    public function extras(SubjectPermissions $subject, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 50) {
            throw new \InvalidArgumentException('community_extras_budget');
        }

        [$where, $params, $types] = $this->access->scope($subject, 't.forum_id');
        $posts = $this->db->fetchAllAssociative('SELECT p.id, p.user_id, t.forum_id, u.username, '
            . "COALESCE(profile.visibility, 'everyone') AS visibility, "
            . "COALESCE(profile.imported_signature, profile.signature, '') AS signature "
            . 'FROM posts p INNER JOIN threads t ON t.id = p.thread_id LEFT JOIN users u ON u.id = p.user_id '
            . 'LEFT JOIN member_profiles profile ON profile.user_id = p.user_id WHERE p.id IN (?) '
            . "AND p.status = 'visible' AND t.status = 'visible' AND p.deleted_at IS NULL AND t.deleted_at IS NULL "
            . 'AND ' . $where, [$ids, ...$params], [ArrayParameterType::INTEGER, ...$types]);
        $result = [];
        $users = array_values(array_unique(array_filter(array_map(intval(...), array_column($posts, 'user_id')))));
        $blocked = [];

        if ($subject->userId !== null && $users !== []) {
            $blocked = array_map(intval(...), $this->db->fetchFirstColumn(
                'SELECT CASE WHEN user_id = ? THEN target_id ELSE user_id END AS id FROM member_blocks '
                    . 'WHERE (user_id = ? AND target_id IN (?)) OR (target_id = ? AND user_id IN (?))',
                [$subject->userId, $subject->userId, $users, $subject->userId, $users],
                [ParameterType::INTEGER, ParameterType::INTEGER, ArrayParameterType::INTEGER,
                    ParameterType::INTEGER, ArrayParameterType::INTEGER],
            ));
        }

        foreach ($posts as $post) {
            $visible = $subject->allows('user.profile.view') && !in_array((int) $post['user_id'], $blocked, true)
                && ((int) $post['user_id'] === $subject->userId || $post['visibility'] === 'everyone'
                    || ($post['visibility'] === 'members' && $subject->userId !== null));
            $result[(int) $post['id']] = ['profile_id' => $visible ? $post['user_id'] : null,
                'signature' => $visible ? $post['signature'] : '', 'avatar' => null,
                'post_id' => (int) $post['id'], 'attachments' => [], 'attachments_more' => false, 'reactions' => [],
                'own' => $subject->userId !== null && (int) $post['user_id'] === $subject->userId];
        }

        $allowed = array_keys($result);

        if ($allowed === []) {
            return [];
        }

        $reactions = $this->db->fetchAllAssociative(
            'SELECT post_id, type_id, COUNT(*) AS total '
            . 'FROM post_reactions WHERE post_id IN (?) GROUP BY post_id, type_id',
            [$allowed],
            [ArrayParameterType::INTEGER]
        );

        foreach ($reactions as $row) {
            $result[(int) $row['post_id']]['reactions'][] = $row;
        }

        // 2026-11-21 benchmark: one ranked query instead of a UNION of one sub-select per post (same rows: at most
        // six newest visible files per post; hidden imported files only for their owner or a forum moderator).
        $moderated = [];
        foreach ($posts as $post) {
            if ($subject->allows('moderation.post.edit_any', (int) $post['forum_id'])) {
                $moderated[] = (int) $post['id'];
            }
        }
        $media = $this->db->fetchAllAssociative(
            'SELECT id, post_id, original_name FROM (SELECT id, post_id, '
                . 'COALESCE(imported_name, original_name) AS original_name, '
                . 'ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY id DESC) AS position '
                . 'FROM community_media WHERE post_id IN (?) AND (user_id IS NOT NULL OR imported_retained = 1) '
                . "AND deleted_at IS NULL AND (imported_visibility IS NULL OR imported_visibility = 'visible' "
                . 'OR user_id = ?' . ($moderated === [] ? '' : ' OR post_id IN (?)') . ')) visible_media '
                . 'WHERE position <= 6 ORDER BY post_id, id DESC',
            [array_map(intval(...), array_column($posts, 'id')), $subject->userId ?? 0,
                ...($moderated === [] ? [] : [$moderated])],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER,
                ...($moderated === [] ? [] : [ArrayParameterType::INTEGER])],
        );
        foreach ($media as $row) {
            $postId = (int) $row['post_id'];
            if (count($result[$postId]['attachments']) < 5) {
                $result[$postId]['attachments'][] = $row;
            } else {
                $result[$postId]['attachments_more'] = true;
            }
        }

        if ($users !== []) {
            $avatars = $this->db->fetchAllKeyValue(
                'SELECT avatar_for, id FROM community_media '
                . 'WHERE avatar_for IN (?) AND user_id IS NOT NULL AND deleted_at IS NULL',
                [$users],
                [ArrayParameterType::INTEGER]
            );

            foreach ($posts as $post) {
                if ($result[(int) $post['id']]['profile_id'] !== null) {
                    $result[(int) $post['id']]['avatar'] = $avatars[(int) $post['user_id']] ?? null;
                }
            }
        }

        return $result;
    }

    public function attachments(SubjectPermissions $subject, int $postId, ?string $before): array
    {
        $post = $this->access->post($subject, $postId);
        if ($before !== null && preg_match('/^[a-f0-9]{32}$/D', $before) !== 1) {
            throw new \Nolviko\Modules\Users\Domain\RegistrationRejected(['community_invalid']);
        }
        return $this->db->fetchAllAssociative(
            'SELECT id, COALESCE(imported_name, original_name) AS original_name '
            . 'FROM community_media WHERE post_id = ? AND (user_id IS NOT NULL OR imported_retained = 1) '
            . "AND deleted_at IS NULL AND (imported_visibility IS NULL OR imported_visibility = 'visible' "
            . 'OR user_id = ? OR ? = 1)' . ($before === null ? '' : ' AND id < ?') . ' ORDER BY id DESC LIMIT 51',
            [$postId, $subject->userId ?? 0, (int) $subject->allows(
                'moderation.post.edit_any',
                (int) $post['forum_id']
            ),
            ...($before === null ? [] : [$before])]
        );
    }

    /**
     * Avatar media IDs of several members for lists (2026-11-08): only where the profile is public, or open to
     * members and the reader is signed in.
     *
     * @param list<int> $users
     * @return array<int, string> User ID => media ID.
     */
    public function avatars(array $users, bool $member): array
    {
        $users = array_values(array_unique(array_filter($users, static fn(int $id): bool => $id > 0)));

        if ($users === []) {
            return [];
        }

        $rows = $this->db->fetchAllKeyValue(
            'SELECT m.avatar_for, m.id FROM community_media m LEFT JOIN member_profiles p ON p.user_id = m.avatar_for '
                . 'WHERE m.avatar_for IN (?) AND m.user_id IS NOT NULL AND m.deleted_at IS NULL '
                . "AND (p.user_id IS NULL OR p.visibility = 'everyone' OR (p.visibility = 'members' AND ? = 1))",
            [$users, (int) $member],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        );
        $result = [];

        foreach ($rows as $user => $media) {
            $result[(int) $user] = (string) $media;
        }

        return $result;
    }

    public function avatar(int $user): ?string
    {
        $id = $this->db->fetchOne('SELECT id FROM community_media WHERE avatar_for = ? '
            . 'AND user_id IS NOT NULL AND deleted_at IS NULL LIMIT 1', [$user]);

        return $id === false ? null : (string) $id;
    }
}
