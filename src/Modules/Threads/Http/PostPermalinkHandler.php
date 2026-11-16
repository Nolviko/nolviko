<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/posts/{post}` (M03.11, ADR-014): resolves the post to its thread page and redirects there with the post's
 * anchor. The position counts visible posts only and is computed from the current state on every request, so
 * deleting or restoring other posts never sends a link to a different post. Moderators reach hidden posts on
 * the page where they are shown (the page of the next visible post; the last page after the last one).
 * Everything a reader may not see is a plain 404, like the thread page.
 */
final class PostPermalinkHandler implements RouteHandler
{
    public function __construct(
        private readonly RequestSubject $subjects,
        private readonly ThreadRepository $threads,
        private readonly PostRepository $posts,
        private readonly ForumVisibility $visibility,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly int $perPage = ThreadPageHandler::DEFAULT_PER_PAGE,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $post = $this->posts->find((int) ($parameters['post'] ?? 0));
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        if ($post === null || $thread === null) {
            return $this->errors->notFound();
        }

        $subject = $this->subjects->of($request);

        if (!$this->visibility->readable($subject)->contains($thread->forumId)) {
            return $this->errors->notFound();
        }

        $moderator = ModerateContent::seesDeleted($subject, $thread->forumId);
        $public = $thread->status === ThreadStatus::Visible && $post->status === PostStatus::Visible;

        if (!$public && !$moderator) {
            return $this->errors->notFound();
        }

        $total = ThreadPageHandler::visibleTotal($thread);
        $before = $this->posts->visibleBefore($post, $total, $thread->firstPostId, $thread->lastPostId);
        $page = min(ThreadPageHandler::pageCount($total, $this->perPage), intdiv($before, $this->perPage) + 1);
        $location = ThreadPageHandler::url($this->urls, $thread->id, $page, $thread->slug) . '#post-' . $post->id;

        // 302, not 301: the page changes when posts before this one are deleted, restored or approved.
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $location,
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
