<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Moderation on one item or many at once (M04.08). Each item goes through the same service as a single action
 * (ModerateContent, ThreadCommands, AccountApprovals) in its own transaction, so permissions, the audit entry
 * and exact counters hold per item, and one refused item does not stop the others. Reject means: soft-delete
 * pending content (restorable) or delete a pending account. Editing stays a single-item action (EditPost).
 */
final class BulkModeration
{
    public const MAX_ITEMS = 100;

    public const POSTS = 'post';

    public const THREADS = 'thread';

    public const USERS = 'user';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const DELETE = 'delete';

    public const RESTORE = 'restore';

    public const MOVE = 'move';

    /** Target => actions it accepts. */
    private const ACTIONS = [
        self::POSTS => [self::APPROVE, self::REJECT, self::DELETE, self::RESTORE],
        self::THREADS => [self::APPROVE, self::REJECT, self::DELETE, self::RESTORE, self::MOVE],
        self::USERS => [self::APPROVE, self::REJECT],
    ];

    public function __construct(
        private readonly ModerateContent $content,
        private readonly ThreadCommands $threads,
        private readonly AccountApprovals $accounts,
    ) {
    }

    /**
     * @param list<int> $ids
     * @param int|null $forumId Target forum of MOVE.
     *
     * @return array<int, string|null> ID => null when applied, otherwise the diagnostic that refused it.
     *
     * @throws ThreadRuleViolation `moderation_denied` (guests), `moderation_action_invalid`, `bulk_empty`,
     *     `bulk_limit` (`max`) or `post_reason_invalid`; these refuse the whole request.
     */
    public function apply(
        SubjectPermissions $subject,
        string $target,
        string $action,
        array $ids,
        string $reason = '',
        ?int $forumId = null,
    ): array {
        $subject->userId ?? throw new ThreadRuleViolation('moderation_denied');

        if (!in_array($action, self::ACTIONS[$target] ?? [], true)) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new ThreadRuleViolation('bulk_empty');
        }

        if (count($ids) > self::MAX_ITEMS) {
            throw new ThreadRuleViolation('bulk_limit', ['max' => self::MAX_ITEMS]);
        }

        $note = EditPost::reason($reason);
        $results = [];

        foreach ($ids as $id) {
            try {
                $this->one($subject, $target, $action, $id, $reason, $note, $forumId);
                $results[$id] = null;
            } catch (ThreadRuleViolation $violation) {
                $results[$id] = $violation->diagnostic;
            }
        }

        return $results;
    }

    private function one(
        SubjectPermissions $subject,
        string $target,
        string $action,
        int $id,
        string $reason,
        ?string $note,
        ?int $forumId,
    ): void {
        $contentAction = match ($action) {
            self::APPROVE => ModerateContent::APPROVE,
            self::RESTORE => ModerateContent::RESTORE,
            default => ModerateContent::DELETE,
        };

        if ($target === self::USERS) {
            if (!$subject->allows(ModerateContent::ADMIN)) {
                throw new ThreadRuleViolation('moderation_denied');
            }

            $action === self::APPROVE
                ? $this->accounts->approve($id, (int) $subject->userId, $note)
                : $this->accounts->reject($id, (int) $subject->userId, $note);
        } elseif ($target === self::THREADS && $action === self::MOVE) {
            $this->threads->run($subject, $id, ThreadCommands::MOVE, $forumId, true, $reason);
        } elseif ($target === self::THREADS) {
            $this->content->thread($subject, $id, $contentAction, $reason);
        } else {
            $this->content->post($subject, $id, $contentAction, $reason);
        }
    }
}
