<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\Post;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * Edits a post (M03.06). The ownership policy:
 * - `moderation.post.edit_any` in the post's forum: any post, also in locked threads and after the window;
 * - otherwise only the author's own post, with `forum.post.edit_own`, while the thread is not locked and within
 *   `forum.post.edit_window_minutes` of posting (0 = no edits after posting; groups combine to the longest).
 *
 * A post the subject may not read (unreadable forum, invisible thread, someone else's pending or hidden post) is
 * reported as not found; a readable post the subject may not edit is `post_edit_denied` (403, also when another
 * user's post ID is put into the URL). Every edit stores a revision with the previous content.
 */
final class EditPost
{
    public const EDIT_OWN = 'forum.post.edit_own';

    public const EDIT_ANY = 'moderation.post.edit_any';

    public const WINDOW = 'forum.post.edit_window_minutes';

    public const MAX_REASON_LENGTH = 255;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly PostRepository $posts,
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly PostEditor $editor,
        private readonly BbcodeRenderer $renderer = new BbcodeRenderer(),
        ?Closure $clock = null,
        private readonly ?ContentPolicy $policy = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @throws ThreadRuleViolation `post_edit_denied` (also for guests), `post_not_found`, `thread_locked` or
     *     `post_edit_window_expired`.
     */
    public function authorize(SubjectPermissions $subject, int $postId): Post
    {
        $userId = $subject->userId ?? throw new ThreadRuleViolation('post_edit_denied');
        $post = $this->posts->find($postId);
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        if (
            $post === null
            || $thread === null
            || $thread->status !== ThreadStatus::Visible
            || !$this->visibility->readable($subject)->contains($thread->forumId)
        ) {
            throw new ThreadRuleViolation('post_not_found');
        }

        $own = $post->userId === $userId;
        $moderator = $subject->allows(self::EDIT_ANY, $thread->forumId);

        if ($post->status !== PostStatus::Visible && !$own && !$moderator) {
            throw new ThreadRuleViolation('post_not_found');
        }

        if ($moderator) {
            return $post;
        }

        if (!$own || !$subject->allows(self::EDIT_OWN, $thread->forumId)) {
            throw new ThreadRuleViolation('post_edit_denied');
        }

        if ($thread->isLocked) {
            throw new ThreadRuleViolation('thread_locked');
        }

        $window = (int) $subject->value(self::WINDOW, $thread->forumId);

        // 0 means no time limit (2026-11-09).
        if ($window > 0 && ($this->clock)()->getTimestamp() >= $post->createdAt->getTimestamp() + $window * 60) {
            throw new ThreadRuleViolation('post_edit_window_expired', ['minutes' => $window]);
        }

        return $post;
    }

    /**
     * @return bool False when the content is unchanged (nothing stored, no empty revision).
     *
     * @throws ThreadRuleViolation Those of authorize(), `post_content_invalid` or `post_reason_invalid`.
     */
    public function edit(SubjectPermissions $subject, int $postId, string $content, string $reason = ''): bool
    {
        $post = $this->authorize($subject, $postId);
        $content = CreateThread::content($content) ?? throw new ThreadRuleViolation('post_content_invalid');
        $reason = self::reason($reason);

        if ($content === $post->content) {
            return false;
        }

        $rendered = $this->renderer->render($content);
        $thread = $this->threads->find($post->threadId);
        $this->policy?->check($subject, $thread === null ? 0 : $thread->forumId, $content, $rendered);

        $this->editor->edit(new EditedPost(
            $post->id,
            (int) $subject->userId,
            $content,
            PostRenderer::FORMAT,
            $rendered,
            BbcodeRenderer::VERSION,
            $reason,
            ($this->clock)(),
        ));

        return true;
    }

    /**
     * Whether the subject may edit the post now (the thread page's Edit link); never throws.
     */
    public function mayEdit(SubjectPermissions $subject, int $postId): bool
    {
        try {
            $this->authorize($subject, $postId);

            return true;
        } catch (ThreadRuleViolation) {
            return false;
        }
    }

    /**
     * New title of the post's thread (2026-11-09): only through its first post, with the same rights as editing it.
     *
     * @return bool False when the title is unchanged or the post is not the first one.
     *
     * @throws ThreadRuleViolation Those of authorize() or `thread_title_invalid`.
     */
    public function retitle(SubjectPermissions $subject, int $postId, string $title): bool
    {
        $post = $this->authorize($subject, $postId);
        $thread = $this->threads->find($post->threadId);

        if ($thread === null || $thread->firstPostId !== $post->id) {
            return false;
        }

        $title = CreateThread::title($title) ?? throw new ThreadRuleViolation('thread_title_invalid');

        if ($title === $thread->title) {
            return false;
        }

        $this->editor->retitle(
            $thread->id,
            $title,
            CreateThread::slug($title),
            (int) $subject->userId,
            ($this->clock)(),
        );

        return true;
    }

    /** Whether the post opens its thread (its edit form then also offers the title). */
    public function threadTitle(int $postId): ?string
    {
        $post = $this->posts->find($postId);
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        return $thread !== null && $thread->firstPostId === $postId ? $thread->title : null;
    }

    /**
     * Trimmed, null when empty. Shared with ModerateContent.
     *
     * @throws ThreadRuleViolation `post_reason_invalid` when not UTF-8, too long or containing control characters.
     */
    public static function reason(string $reason): ?string
    {
        if (!mb_check_encoding($reason, 'UTF-8')) {
            throw new ThreadRuleViolation('post_reason_invalid');
        }

        $reason = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $reason));

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH || preg_match('/\p{Cc}/u', $reason) === 1) {
            throw new ThreadRuleViolation('post_reason_invalid');
        }

        return $reason === '' ? null : $reason;
    }
}
