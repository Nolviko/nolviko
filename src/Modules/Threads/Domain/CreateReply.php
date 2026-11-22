<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\PostingInput;
use Nolviko\Core\RateLimit\ClientNetwork;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;

/**
 * Creates a reply (M03.05): permission, lock, input and approval rules, then one atomic write through
 * ReplyWriter. Each reply form carries a token (`newToken()`); submitting the same form twice returns the first
 * post instead of writing a second one. A thread the subject may not read is reported as not found.
 */
final class CreateReply
{
    public const REPLY = 'forum.post.reply';

    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    /** Default client-network interval (M03.15; config `posting.ip_interval_seconds`). */
    public const NETWORK_INTERVAL = 15;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly PostRepository $posts,
        private readonly ReplyWriter $writer,
        private readonly BbcodeRenderer $renderer = new BbcodeRenderer(),
        ?Closure $clock = null,
        private readonly int $networkSeconds = self::NETWORK_INTERVAL,
        private readonly ?ContentPolicy $policy = null,
        private readonly ?BeforeHooks $before = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * A fresh form token: 128 random bits as 32 lowercase hex digits.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether the subject may reply to the thread at all (the form page asks before showing the form).
     *
     * @throws ThreadRuleViolation `reply_permission_denied` (also for guests), `thread_not_found` or
     *     `thread_locked`.
     */
    public function authorize(SubjectPermissions $subject, int $threadId): Thread
    {
        if ($subject->userId === null) {
            throw new ThreadRuleViolation('reply_permission_denied');
        }

        $thread = $this->threads->find($threadId);

        if (
            $thread === null
            || $thread->status !== ThreadStatus::Visible
            || $thread->movedToThreadId !== null
            || !$this->visibility->readable($subject)->contains($thread->forumId)
        ) {
            throw new ThreadRuleViolation('thread_not_found');
        }

        if (!$subject->allows(self::REPLY, $thread->forumId)) {
            throw new ThreadRuleViolation('reply_permission_denied');
        }

        if ($thread->isLocked && !$subject->allows(CreateThread::LOCK_BYPASS, $thread->forumId)) {
            throw new ThreadRuleViolation('thread_locked');
        }

        return $thread;
    }

    /**
     * @throws ThreadRuleViolation Those of authorize(), `post_token_invalid`, `post_content_invalid`,
     *     `post_flood_wait` (`seconds`) or the ContentPolicy violations.
     */
    public function reply(
        SubjectPermissions $subject,
        int $threadId,
        string $token,
        string $content,
        ?string $clientIp = null,
    ): CreatedReply {
        $thread = $this->authorize($subject, $threadId);
        $userId = (int) $subject->userId;

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new ThreadRuleViolation('post_token_invalid');
        }

        $content = CreateThread::content($content) ?? throw new ThreadRuleViolation('post_content_invalid');

        try {
            $content = $this->before?->posting(new PostingInput($userId, $thread->forumId, $content)) ?? $content;
        } catch (ExtensionFailure $failure) {
            $prefix = 'extension_hook_denied:';
            $parameters = str_starts_with($failure->getMessage(), $prefix)
                ? ['extension_reason' => substr($failure->getMessage(), strlen($prefix))] : [];

            throw new ThreadRuleViolation('post_content_blocked', $parameters);
        }

        $content = CreateThread::content($content) ?? throw new ThreadRuleViolation('post_content_invalid');
        $rendered = $this->renderer->render($content);
        $this->policy?->check($subject, $thread->forumId, $content, $rendered);
        $firstPosts = $this->policy?->rules->firstPostsModerated ?? 1;

        return $this->writer->reply(new NewReply(
            $thread->id,
            $thread->forumId,
            $userId,
            $token,
            $content,
            PostRenderer::FORMAT,
            $rendered,
            $this->renderer->version(),
            (new PostApproval($this->posts, $firstPosts))->status($subject, $userId, $thread->forumId),
            ($this->clock)(),
            (int) $subject->value(CreateThread::FLOOD, $thread->forumId),
            $subject->allows(CreateThread::LOCK_BYPASS, $thread->forumId),
            ClientNetwork::key($clientIp),
            $this->networkSeconds,
        ));
    }
}
