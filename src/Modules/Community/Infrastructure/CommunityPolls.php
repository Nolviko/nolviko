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
        int $days,
        bool $allowChange = false,
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
            $allowChange,
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
                'question' => $question, 'multiple_choice' => (int) $multiple, 'closes_at' => $close,
                'allow_change' => (int) $allowChange]);

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
                . 'allow_change, '
                . '(closes_at IS NOT NULL '
                . 'AND closes_at <= CURRENT_TIMESTAMP(6)) AS closed FROM thread_polls WHERE thread_id = ? FOR UPDATE',
                [$threadId]
            );

            if (
                $poll === false || (int) $poll['imported_lock'] !== 0 || (int) $poll['closed'] === 1
                    || (int) $thread['is_locked'] === 1
                || ((int) $poll['multiple_choice'] === 0 && count($selected) !== 1)
                || ($poll['imported_max_choices'] !== null && count($selected) > (int) $poll['imported_max_choices'])
            ) {
                throw new RegistrationRejected(['community_invalid']);
            }

            // One ballot per member; with allow_change (roadmap 25) a new vote replaces the old ballot.
            if (
                $db->fetchOne(
                    'SELECT user_id FROM poll_ballots WHERE user_id = ? AND thread_id = ?',
                    [$actor, $threadId],
                ) !== false
            ) {
                if ((int) $poll['allow_change'] !== 1) {
                    throw new RegistrationRejected(['community_invalid']);
                }

                $db->delete('poll_votes', ['user_id' => $actor, 'thread_id' => $threadId]);
                $db->delete('poll_ballots', ['user_id' => $actor, 'thread_id' => $threadId]);
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

    /** Withdraws the member's vote while the poll allows changes and is open (roadmap 25). */
    public function retract(SubjectPermissions $subject, int $threadId): void
    {
        $actor = $this->access->actor($subject);
        $this->db->transactional(function (Connection $db) use ($subject, $actor, $threadId): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $poll = $db->fetchAssociative('SELECT allow_change, (closes_at IS NOT NULL AND closes_at <= '
                . 'CURRENT_TIMESTAMP(6)) AS closed FROM thread_polls WHERE thread_id = ? FOR UPDATE', [$threadId]);

            if (
                $poll === false || (int) $poll['allow_change'] !== 1 || (int) $poll['closed'] === 1
                || (int) $thread['is_locked'] === 1
            ) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $db->delete('poll_votes', ['user_id' => $actor, 'thread_id' => $threadId]);
            $db->delete('poll_ballots', ['user_id' => $actor, 'thread_id' => $threadId]);
        });
    }

    /**
     * Edits a published poll (roadmap 23), by its author or a moderator: question, option labels, extra options,
     * closing and the vote-change setting. Renaming an option keeps its votes; an option removed by `remove`
     * loses its votes, and ballots left without a vote are removed, so the one-ballot rule still holds. Imported
     * polls keep their imported text (the importer's columns win on display).
     *
     * @param array<int, string> $labels Option ID => new label ('' removes the option).
     * @param list<string> $added New option labels.
     */
    public function edit(
        SubjectPermissions $subject,
        int $threadId,
        string $question,
        array $labels,
        array $added,
        int $days,
        bool $allowChange,
    ): void {
        $actor = $this->access->actor($subject);
        $question = CommunityAccess::text($question, 240);
        $added = array_values(array_filter(array_map(
            static fn(string $value): string => CommunityAccess::text($value, 160),
            $added,
        )));

        if ($question === '' || $days < 0 || $days > 365) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->transactional(function (Connection $db) use (
            $subject,
            $actor,
            $threadId,
            $question,
            $labels,
            $added,
            $days,
            $allowChange,
        ): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $this->owner($subject, $actor, $thread);
            $current = $db->fetchAllKeyValue(
                'SELECT id, label FROM poll_options WHERE thread_id = ? ORDER BY position FOR UPDATE',
                [$threadId],
            );

            if ($current === []) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $keep = [];

            foreach ($current as $id => $label) {
                $new = CommunityAccess::text((string) ($labels[(int) $id] ?? $label), 160);

                if ($new !== '') {
                    $keep[(int) $id] = $new;
                }
            }

            $all = [...array_values($keep), ...$added];

            if (count($all) < 2 || count($all) > 10 || count(array_unique($all)) !== count($all)) {
                throw new RegistrationRejected(['community_invalid']);
            }

            $close = $days === 0 ? null : (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u');
            $db->update('thread_polls', ['question' => $question, 'closes_at' => $close,
                'allow_change' => (int) $allowChange], ['thread_id' => $threadId]);

            foreach ($current as $id => $label) {
                if (!isset($keep[(int) $id])) {
                    $db->delete('poll_votes', ['thread_id' => $threadId, 'option_id' => (int) $id]);
                    $db->delete('poll_options', ['id' => (int) $id]);
                } elseif ($keep[(int) $id] !== $label) {
                    $db->update('poll_options', ['label' => $keep[(int) $id]], ['id' => (int) $id]);
                }
            }

            $position = count($current);

            foreach ($added as $label) {
                $db->insert('poll_options', ['thread_id' => $threadId, 'label' => $label, 'position' => $position++]);
            }

            $db->executeStatement(
                'DELETE FROM poll_ballots WHERE thread_id = ? AND NOT EXISTS (SELECT 1 FROM poll_votes v '
                    . 'WHERE v.thread_id = poll_ballots.thread_id AND v.user_id = poll_ballots.user_id)',
                [$threadId],
            );
        });
    }

    /** Removes the poll (`remove`) or only its votes (`reset`), by its author or a moderator (roadmap 23). */
    public function clear(SubjectPermissions $subject, int $threadId, bool $remove): void
    {
        $actor = $this->access->actor($subject);
        $this->db->transactional(function (Connection $db) use ($subject, $actor, $threadId, $remove): void {
            $thread = $this->access->thread($subject, $threadId, true);
            $this->owner($subject, $actor, $thread);
            $db->delete('poll_votes', ['thread_id' => $threadId]);
            $db->delete('poll_ballots', ['thread_id' => $threadId]);

            if ($remove) {
                $db->delete('poll_options', ['thread_id' => $threadId]);
                $db->delete('thread_polls', ['thread_id' => $threadId]);
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
