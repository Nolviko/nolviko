<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;

/**
 * A member reports a post to the moderators (M04.07a). Needs a signed-in user with `forum.post.report` in the
 * post's forum; only posts the reporter can read are reportable (anything else is `post_not_found`, also for a
 * guessed post ID); own posts cannot be reported. One open report per user and post; one report per
 * INTERVAL_SECONDS per user. The report is for moderators only: nothing about it is shown to the author.
 */
final class ReportPost
{
    public const PERMISSION = 'forum.post.report';

    public const INTERVAL_SECONDS = 30;

    public const MAX_REASON_LENGTH = 500;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly PostRepository $posts,
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly ReportWriter $writer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @return int The reporter's user ID.
     *
     * @throws ThreadRuleViolation `report_denied` (also for guests), `post_not_found` or `report_own_post`.
     */
    public function authorize(SubjectPermissions $subject, int $postId): int
    {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('report_denied');
        $post = $this->posts->find($postId);
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        if (
            $post === null
            || $thread === null
            || $post->status !== PostStatus::Visible
            || $thread->status !== ThreadStatus::Visible
            || !$this->visibility->readable($subject)->contains($thread->forumId)
        ) {
            throw new ThreadRuleViolation('post_not_found');
        }

        if (!$subject->allows(self::PERMISSION, $thread->forumId)) {
            throw new ThreadRuleViolation('report_denied');
        }

        if ($post->userId === $userId) {
            throw new ThreadRuleViolation('report_own_post');
        }

        return $userId;
    }

    /**
     * @return int The report ID.
     *
     * @throws ThreadRuleViolation Those of authorize(), `report_reason_invalid` (`max`), `report_exists` or
     *     `report_flood_wait` (`seconds`).
     */
    public function report(SubjectPermissions $subject, int $postId, string $reason): int
    {
        $userId = $this->authorize($subject, $postId);
        $reason = self::reason($reason) ?? throw new ThreadRuleViolation(
            'report_reason_invalid',
            ['max' => self::MAX_REASON_LENGTH],
        );

        return $this->writer->create($postId, $userId, $reason, ($this->clock)(), self::INTERVAL_SECONDS);
    }

    private static function reason(string $reason): ?string
    {
        if (!mb_check_encoding($reason, 'UTF-8')) {
            return null;
        }

        $reason = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $reason));
        $length = mb_strlen($reason);

        return $length === 0 || $length > self::MAX_REASON_LENGTH || preg_match('/\p{Cc}/u', $reason) === 1
            ? null
            : $reason;
    }
}
