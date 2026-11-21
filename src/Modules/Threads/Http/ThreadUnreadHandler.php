<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/threads/{thread}/unread` (beta.18, roadmap 13): sends a member to the first post of a thread they have not
 * read, on the page that holds it, with the post anchor. A thread read to the end goes to its last post; a guest
 * goes to the first page. An unreadable, unknown or not visible thread is a plain 404. Costs one read-mark lookup
 * and one index seek on `ix_posts_thread` besides the page position the permalink also computes.
 */
final class ThreadUnreadHandler implements RouteHandler
{
    public function __construct(
        private readonly RequestSubject $subjects,
        private readonly ThreadRepository $threads,
        private readonly PostRepository $posts,
        private readonly ForumVisibility $visibility,
        private readonly ReadTracker $reads,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly int $perPage = ThreadPageHandler::DEFAULT_PER_PAGE,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $thread = $this->threads->find((int) ($parameters['thread'] ?? 0));
        $subject = $this->subjects->of($request);

        if (
            $thread === null || $thread->status !== ThreadStatus::Visible
            || !$this->visibility->readable($subject)->contains($thread->forumId)
        ) {
            return $this->errors->notFound();
        }

        if ($thread->movedToThreadId !== null) {
            return self::found($this->urls->generate(
                CoreRoutes::THREAD_UNREAD,
                ['thread' => (string) $thread->movedToThreadId],
            ));
        }

        $postId = $subject->userId === null ? null : ($this->reads->firstUnreadPost(
            $subject->userId,
            $thread->id,
            $thread->forumId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ) ?? $thread->lastPostId);
        $post = $postId === null ? null : $this->posts->find($postId);

        if ($post === null) {
            return self::found(ThreadPageHandler::url($this->urls, $thread->id, 1, $thread->slug));
        }

        $total = ThreadPageHandler::visibleTotal($thread);
        $before = $this->posts->visibleBefore($post, $total, $thread->firstPostId, $thread->lastPostId);
        $page = min(ThreadPageHandler::pageCount($total, $this->perPage), intdiv($before, $this->perPage) + 1);

        return self::found(ThreadPageHandler::url($this->urls, $thread->id, $page, $thread->slug) . '#post-' . $post->id);
    }

    /** 302 and never cached: the target moves with every post the member reads. */
    private static function found(string $location): Response
    {
        return new Response('', Response::HTTP_FOUND, ['Location' => $location, 'Cache-Control' => 'private, no-store']);
    }
}
