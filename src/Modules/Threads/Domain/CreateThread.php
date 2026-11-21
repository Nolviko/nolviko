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
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Normalizer;
use Transliterator;

/**
 * Creates a thread with its first post (M03.04): permission, lock, input and approval rules, then one atomic
 * write through ThreadWriter, which also checks the flood interval under the user's row lock (M03.05). A forum
 * the subject may not see is reported as not found, so its existence does not leak. CSRF is checked by the
 * kernel guard before the handler runs.
 */
final class CreateThread
{
    public const MAX_TITLE_LENGTH = 200;

    /** ADR-012: checked on save, never truncated. */
    public const MAX_CONTENT_LENGTH = 50000;

    public const MAX_SLUG_LENGTH = 80;

    public const CREATE = 'forum.thread.create';

    public const LOCK_BYPASS = 'moderation.thread.lock';

    public const FLOOD = 'forum.post.flood_interval_seconds';

    private const SLUG_RULES = 'Any-Latin; Latin-ASCII; Lower()'; // i18n-ignore: ICU transform rules

    private const FALLBACK_SLUG = 'thread';

    /** Default client-network interval (M03.15; config `posting.ip_interval_seconds`). */
    public const NETWORK_INTERVAL = 15;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly ForumRepository $forums,
        private readonly ForumVisibility $visibility,
        private readonly PostRepository $posts,
        private readonly ThreadWriter $writer,
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
     * @throws ThreadRuleViolation `thread_forum_not_found`, `thread_forum_is_category`, `thread_permission_denied`,
     *     `thread_forum_locked`, `thread_title_invalid`, `post_content_invalid`, `post_flood_wait` (`seconds`)
     *     or the ContentPolicy violations.
     */
    public function create(
        SubjectPermissions $subject,
        int $forumId,
        string $title,
        string $content,
        ?string $clientIp = null,
    ): CreatedThread {
        $this->authorize($subject, $forumId);
        $userId = (int) $subject->userId;
        $title = self::title($title) ?? throw new ThreadRuleViolation('thread_title_invalid');
        $content = self::content($content) ?? throw new ThreadRuleViolation('post_content_invalid');

        try {
            $content = $this->before?->posting(new PostingInput($userId, $forumId, $content)) ?? $content;
        } catch (ExtensionFailure $failure) {
            $prefix = 'extension_hook_denied:';
            $parameters = str_starts_with($failure->getMessage(), $prefix)
                ? ['extension_reason' => substr($failure->getMessage(), strlen($prefix))] : [];

            throw new ThreadRuleViolation('post_content_blocked', $parameters);
        }

        $content = self::content($content) ?? throw new ThreadRuleViolation('post_content_invalid');
        $rendered = $this->renderer->render($content);
        $this->policy?->check($subject, $forumId, $title . "\n" . $content, $rendered);
        $firstPosts = $this->policy?->rules->firstPostsModerated ?? 1;
        $status = (new PostApproval($this->posts, $firstPosts))->status($subject, $userId, $forumId);

        return $this->writer->create(new NewThread(
            $forumId,
            $userId,
            $title,
            self::slug($title),
            $content,
            PostRenderer::FORMAT,
            $rendered,
            $this->renderer->version(),
            $status === PostStatus::Visible ? ThreadStatus::Visible : ThreadStatus::Pending,
            $status,
            ($this->clock)(),
            (int) $subject->value(self::FLOOD, $forumId),
            ClientNetwork::key($clientIp),
            $this->networkSeconds,
        ));
    }

    /**
     * Whether the subject may start a thread in the forum at all (the form page asks before showing the form).
     *
     * @throws ThreadRuleViolation `thread_permission_denied` (also for guests), `thread_forum_not_found`,
     *     `thread_forum_is_category` or `thread_forum_locked`.
     */
    public function authorize(SubjectPermissions $subject, int $forumId): Forum
    {
        if ($subject->userId === null) {
            throw new ThreadRuleViolation('thread_permission_denied');
        }

        $forum = $this->forums->find($forumId);

        if ($forum === null || !$this->visibility->visible($subject)->contains($forumId)) {
            throw new ThreadRuleViolation('thread_forum_not_found');
        }

        if ($forum->type !== ForumType::Forum) {
            throw new ThreadRuleViolation('thread_forum_is_category');
        }

        if (!$subject->allows(self::CREATE, $forumId)) {
            throw new ThreadRuleViolation('thread_permission_denied');
        }

        if ($forum->isLocked && !$subject->allows(self::LOCK_BYPASS, $forumId)) {
            throw new ThreadRuleViolation('thread_forum_locked');
        }

        return $forum;
    }

    /**
     * NFC, outer whitespace trimmed and inner runs collapsed; null when empty, too long, not UTF-8 or containing
     * control characters.
     */
    public static function title(string $title): ?string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            return null;
        }

        $title = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', (string) Normalizer::normalize($title)));
        $length = mb_strlen($title);

        if ($length === 0 || $length > self::MAX_TITLE_LENGTH || preg_match('/\p{Cc}/u', $title) === 1) {
            return null;
        }

        return $title;
    }

    /**
     * Line endings as LF and outer whitespace trimmed; null when empty, too long, not UTF-8 or containing NUL.
     */
    public static function content(string $content): ?string
    {
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return null;
        }

        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
        $length = mb_strlen($content);

        return $length === 0 || $length > self::MAX_CONTENT_LENGTH ? null : $content;
    }

    /**
     * ASCII URL slug from the title: transliterated, lowercase, hyphen-separated, at most MAX_SLUG_LENGTH bytes
     * cut at a word boundary; `thread` when nothing is left.
     */
    public static function slug(string $title): string
    {
        $transliterator = Transliterator::create(self::SLUG_RULES);
        $ascii = $transliterator === null ? $title : (string) $transliterator->transliterate($title);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        if (strlen($slug) > self::MAX_SLUG_LENGTH) {
            $cut = substr($slug, 0, self::MAX_SLUG_LENGTH + 1);
            $boundary = strrpos($cut, '-');
            $slug = rtrim(substr($slug, 0, $boundary === false ? self::MAX_SLUG_LENGTH : $boundary), '-');
        }

        return $slug === '' ? self::FALLBACK_SLUG : $slug;
    }
}
