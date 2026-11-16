<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Poll ballots and accepted answers use locked thread rows and server-side ownership. */
final class CommunityPolls
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommunityAccess $access,
    ) {
    }

    public function create(
        SubjectPermissions $subject,
        int $threadId,
        string $question,
        array $options,
        bool $multiple,
        int $days
    ): void {
        $actor = $this->access->actor($subject);
        $question = CommunityAccess::text($question, 240);
        $options = array_map(static fn(string $value): string => CommunityAccess::text($value, 160), $options);

        if (
            $question === '' || count($options) < 2 || count($options) > 10 || in_array('', $options, true)
            || count(array_unique($options)) !== count($options) || $days < 0 || $days > 365
        ) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->transactional(function (Connection $db) use (
            $subject,
            $actor,
            $threadId,
            $question,
            $options,
            $multiple,
            $days,
        ): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $this->owner($subject, $actor, $thread);

            if (
                (int) $thread['is_locked'] === 1 || $db->fetchOne(
                    'SELECT thread_id FROM thread_polls WHERE thread_id = ?',
                    [$threadId],
                ) !== false
            ) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $close = $days === 0 ? null : (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u');
            $db->insert('thread_polls', ['thread_id' => $threadId, 'user_id' => $actor,
                'question' => $question, 'multiple_choice' => (int) $multiple, 'closes_at' => $close]);

            foreach ($options as $position => $label) {
                $db->insert('poll_options', ['thread_id' => $threadId, 'label' => $label, 'position' => $position]);
            }
        });
    }

    public function vote(SubjectPermissions $subject, int $threadId, array $selected): void
    {
        $actor = $this->access->actor($subject);
        $selected = array_values(array_unique(array_map(intval(...), $selected)));

        if (count($selected) < 1 || count($selected) > 5 || min($selected) < 1) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->transactional(function (Connection $db) use ($subject, $actor, $threadId, $selected): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $poll = $db->fetchAssociative(
                'SELECT thread_id, user_id, question, multiple_choice, closes_at, imported_lock, imported_max_choices, '
                . '(closes_at IS NOT NULL '
                . 'AND closes_at <= CURRENT_TIMESTAMP(6)) AS closed FROM thread_polls WHERE thread_id = ? FOR UPDATE',
                [$threadId]
            );

            if (
                $poll === false || (int) $poll['imported_lock'] !== 0 || (int) $poll['closed'] === 1
                    || (int) $thread['is_locked'] === 1
                || ((int) $poll['multiple_choice'] === 0 && count($selected) !== 1)
                || ($poll['imported_max_choices'] !== null && count($selected) > (int) $poll['imported_max_choices'])
                || $db->fetchOne(
                    'SELECT user_id FROM poll_ballots WHERE user_id = ? AND thread_id = ?',
                    [$actor, $threadId]
                ) !== false
            ) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $options = array_map(intval(...), $db->fetchFirstColumn(
                'SELECT id FROM poll_options WHERE thread_id = ?',
                [$threadId],
            ));

            if (array_diff($selected, $options) !== []) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $db->insert('poll_ballots', ['user_id' => $actor, 'thread_id' => $threadId,
                'created_at' => CommunityInteractions::now()]);

            foreach ($selected as $option) {
                $db->insert('poll_votes', ['user_id' => $actor, 'thread_id' => $threadId, 'option_id' => $option]);
            }
        });
    }

    public function solution(SubjectPermissions $subject, int $threadId, int $postId): void
    {
        $actor = $this->access->actor($subject);
        $this->db->transactional(function (Connection $db) use ($subject, $actor, $threadId, $postId): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $this->owner($subject, $actor, $thread);

            // Only question-and-answer forums have accepted answers (2026-11-14).
            $forum = (int) $thread['forum_id'];

            if ((int) $db->fetchOne('SELECT is_questions FROM forums WHERE id = ?', [$forum]) !== 1) {
                throw new RegistrationRejected(['community_solution_off']);
            }

            if ($postId > 0) {
                $post = $this->access->post($subject, $postId, true);

                if ((int) $post['thread_id'] !== $threadId || (int) $thread['first_post_id'] === $postId) {
                    throw new RegistrationRejected(['community_invalid']);
                }
            }

            $db->delete('thread_solutions', ['thread_id' => $threadId]);

            if ($postId > 0) {
                $db->insert('thread_solutions', ['thread_id' => $threadId, 'post_id' => $postId,
                    'user_id' => $actor, 'created_at' => CommunityInteractions::now()]);
            }
        });
    }

    private function owner(SubjectPermissions $subject, int $actor, array $thread): void
    {
        if (
            (int) $thread['user_id'] !== $actor
            && !$subject->allows('moderation.post.edit_any', (int) $thread['forum_id'])
        ) {
            throw new RegistrationRejected(['community_denied']);
        }
    }
}
