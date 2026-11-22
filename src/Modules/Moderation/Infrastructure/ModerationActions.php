<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Throwable;

/**
 * Reusable moderation actions (beta.18 roadmap 40): an administrator names a sequence (lock or unlock, stick or
 * unstick, a prefix, a reply, a move) and moderators run it on a thread in one step. Every step goes through the
 * normal command with the moderator's own permissions, so an action never grants more than its steps would; an
 * already reached state (`thread_state_unchanged`) is skipped. The move runs last.
 */
final class ModerationActions
{
    public const MODES = ['', 'on', 'off'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{id: int, title: string, move_forum_id: ?int, lock_mode: string, sticky_mode: string,
     *     prefix_id: ?int, reply_text: ?string}> */
    public function all(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id, title, move_forum_id, lock_mode, sticky_mode, '
                . 'prefix_id, reply_text FROM moderation_actions ORDER BY title, id LIMIT 100');
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'],
            'move_forum_id' => $row['move_forum_id'] === null ? null : (int) $row['move_forum_id'],
            'lock_mode' => (string) $row['lock_mode'], 'sticky_mode' => (string) $row['sticky_mode'],
            'prefix_id' => $row['prefix_id'] === null ? null : (int) $row['prefix_id'],
            'reply_text' => $row['reply_text'] === null ? null : (string) $row['reply_text']], $rows);
    }

    /** @throws ThreadRuleViolation `moderation_action_invalid` (no title or no step). */
    public function add(
        string $title,
        ?int $moveForumId,
        string $lockMode,
        string $stickyMode,
        ?int $prefixId,
        string $replyText,
    ): void {
        $title = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title) ?? ''), 0, 100);
        $replyText = trim(str_replace("\r\n", "\n", $replyText));

        if (
            $title === '' || !in_array($lockMode, self::MODES, true) || !in_array($stickyMode, self::MODES, true)
            || ($moveForumId === null && $lockMode === '' && $stickyMode === '' && $prefixId === null
                && $replyText === '')
        ) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $now = gmdate('Y-m-d H:i:s.000000');
        $this->db->insert('moderation_actions', ['title' => $title, 'move_forum_id' => $moveForumId,
            'lock_mode' => $lockMode, 'sticky_mode' => $stickyMode, 'prefix_id' => $prefixId,
            'reply_text' => $replyText === '' ? null : mb_substr($replyText, 0, 20000), 'created_at' => $now,
            'updated_at' => $now]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('moderation_actions', ['id' => $id]);
    }

    /**
     * Runs one action on a thread as the subject.
     *
     * @throws ThreadRuleViolation `moderation_action_invalid` for an unknown action, or the first refusal of a step.
     */
    public function apply(
        SubjectPermissions $subject,
        int $threadId,
        int $actionId,
        ThreadCommands $commands,
        ThreadRepository $threads,
        ?ThreadTaxonomy $taxonomy,
        ?CreateReply $replies,
        ?string $clientIp,
    ): void {
        $action = null;

        foreach ($this->all() as $candidate) {
            $action = $candidate['id'] === $actionId ? $candidate : $action;
        }

        if ($action === null) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $reason = $action['title'];
        $steps = array_filter([
            $action['lock_mode'] === '' ? null : ($action['lock_mode'] === 'on' ? ThreadCommands::LOCK
                : ThreadCommands::UNLOCK),
            $action['sticky_mode'] === '' ? null : ($action['sticky_mode'] === 'on' ? ThreadCommands::STICKY
                : ThreadCommands::UNSTICKY),
        ]);

        foreach ($steps as $command) {
            $this->step(static fn() => $commands->run($subject, $threadId, $command, null, false, $reason));
        }

        if ($action['prefix_id'] !== null && $taxonomy !== null) {
            $thread = $threads->find($threadId) ?? throw new ThreadRuleViolation('thread_not_found');

            // The labels page asks the same permission (ThreadLabelsHandler).
            if (!$subject->allows(ModerateContent::MODERATE, $thread->forumId)) {
                throw new ThreadRuleViolation('moderation_denied');
            }

            $tags = array_map(
                static fn(array $tag): int => $tag['id'],
                $taxonomy->forThreads([$threadId])[$threadId]['tags'] ?? [],
            );
            $taxonomy->assign($threadId, $thread->forumId, $action['prefix_id'], $tags, $subject->userId);
        }

        if ($action['reply_text'] !== null && $replies !== null) {
            $replies->reply($subject, $threadId, bin2hex(random_bytes(16)), $action['reply_text'], $clientIp);
        }

        if ($action['move_forum_id'] !== null) {
            $this->step(static fn() => $commands->run(
                $subject,
                $threadId,
                ThreadCommands::MOVE,
                $action['move_forum_id'],
                true,
                $reason,
            ));
        }
    }

    private function step(Closure $command): void
    {
        try {
            $command();
        } catch (ThreadRuleViolation $violation) {
            if ($violation->diagnostic !== 'thread_state_unchanged') {
                throw $violation;
            }
        }
    }
}
