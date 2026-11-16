<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/posts/{post}/history` (2026-11-08): earlier versions of an edited post, newest first, with editor, time and
 * reason. Only the post's author and the forum's moderators may open it; everyone else, and every post they may
 * not read, gets the same 404. Versions are shown as escaped source text, never rendered, so an old version
 * cannot bring back markup the current rules would refuse.
 */
final readonly class PostHistoryHandler implements RouteHandler
{
    public const MAX_VERSIONS = 50;

    public function __construct(
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private RequestSubject $subjects,
        private PostRepository $posts,
        private ThreadRepository $threads,
        private UserRepository $users,
        private ForumVisibility $visibility,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $post = $this->posts->find((int) ($parameters['post'] ?? 0));
        $thread = $post === null ? null : $this->threads->find($post->threadId);

        if ($post === null || $thread === null || !$this->visibility->readable($subject)->contains($thread->forumId)) {
            return $this->errors->notFound();
        }

        $moderator = ModerateContent::seesDeleted($subject, $thread->forumId);
        $author = $subject->userId !== null && $post->userId === $subject->userId;
        $visible = $post->status === PostStatus::Visible && $thread->status === ThreadStatus::Visible;

        if (!($moderator || ($author && $visible))) {
            return $this->errors->notFound();
        }

        $names = [];
        $versions = [];

        foreach (array_slice(array_reverse($this->posts->revisions($post->id)), 0, self::MAX_VERSIONS) as $revision) {
            $editor = $revision['editor_id'];

            if ($editor !== null && !array_key_exists($editor, $names)) {
                $names[$editor] = $this->users->findById($editor)?->username;
            }

            $versions[] = [
                'editor' => $editor === null ? null : $names[$editor],
                'at' => $revision['created_at'],
                'reason' => $revision['change_reason'],
                'content' => $revision['previous_content'],
            ];
        }

        $html = $this->views->render('threads/history.twig', [
            'title' => $this->translator->translate('threads.history.title', ['thread' => $thread->title]),
            'post_url' => $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $post->id]),
            'current' => $post->content,
            'edited_at' => $post->editedAt,
            'versions' => $versions,
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
