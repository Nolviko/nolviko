<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Http\ForumOptions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/threads/{thread}/posts` (2026-11-08): split, move or merge posts. The moderator ticks posts (PER_PAGE per
 * page, `?page=`), then sends them to a new thread (title and forum) or to an existing thread (ID or its
 * address); "all posts" merges the whole thread into the existing one. ThreadCommands checks every choice.
 */
final readonly class ThreadPostsHandler implements RouteHandler
{
    public const PER_PAGE = 100;

    public function __construct(
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private RequestSubject $subjects,
        private ThreadCommands $commands,
        private ThreadRepository $threads,
        private PostRepository $posts,
        private ForumRepository $forums,
        private ForumVisibility $visibility,
        private CsrfTokens $csrf,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $subject = $this->subjects->of($request);
        $thread = $this->threads->find((int) ($parameters['thread'] ?? 0));

        if (
            $thread === null || $thread->movedToThreadId !== null
            || !$this->visibility->readable($subject)->contains($thread->forumId)
        ) {
            return $this->errors->notFound();
        }

        if (!$subject->allows('moderation.thread.move', $thread->forumId)) {
            return $this->errors->forbidden();
        }

        $input = $request->request->all();
        $form = [
            'mode' => ($input['mode'] ?? '') === 'existing' ? 'existing' : 'new',
            'title' => is_string($input['title'] ?? null) ? $input['title'] : '',
            'target' => is_string($input['target'] ?? null) ? $input['target'] : '',
            'reason' => is_string($input['reason'] ?? null) ? $input['reason'] : '',
            'all' => ($input['all'] ?? '') === '1',
        ];
        $selected = array_map(intval(...), array_filter(
            is_array($input['posts'] ?? null) ? $input['posts'] : [],
            static fn(mixed $id): bool => is_string($id) && ctype_digit($id),
        ));
        $error = null;

        if ($request->isMethod('POST')) {
            $forum = is_string($input['forum'] ?? null) && ctype_digit($input['forum']) ? (int) $input['forum'] : null;

            try {
                $target = $this->commands->movePosts(
                    $subject,
                    $thread->id,
                    $selected,
                    $form['all'],
                    $form['mode'] === 'existing' || $form['all'] ? self::threadId($form['target']) ?? 0 : null,
                    $forum,
                    $form['title'],
                    $form['reason'],
                );

                return InstallerPage::redirect(
                    $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $target]),
                );
            } catch (ThreadRuleViolation $violation) {
                $error = $this->translator->translate(
                    'diagnostics.' . $violation->diagnostic,
                    $violation->diagnostic === 'post_reason_invalid' ? ['max' => EditPost::MAX_REASON_LENGTH]
                        : $violation->parameters,
                );
            }
        }

        $page = max(1, $request->query->getInt('page', 1));
        $total = $thread->replyCount + 1;
        $rows = $this->posts->threadPage(
            $thread->id,
            $page,
            self::PER_PAGE,
            $total,
            ModerateContent::seesDeleted($subject, $thread->forumId),
        );
        $base = $this->urls->generate(CoreRoutes::THREAD_POSTS, ['thread' => (string) $thread->id]);

        return new Response($this->views->render('threads/posts.twig', [
            'title' => $this->translator->translate('threads.posts.title', ['thread' => $thread->title]),
            'thread_url' => $this->urls->generate(
                CoreRoutes::THREAD_SHOW,
                ['slug' => $thread->slug, 'thread' => (string) $thread->id],
            ),
            'action' => $base . ($page > 1 ? '?page=' . $page : ''),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'posts' => array_map(static fn(PagedPost $row): array => [
                'id' => $row->post->id,
                'author' => $row->authorName,
                'at' => $row->post->createdAt,
                'excerpt' => (string) grapheme_substr(
                    trim((string) preg_replace('/\s+/u', ' ', $row->post->content)),
                    0,
                    160,
                ),
                'checked' => in_array($row->post->id, $selected, true),
            ], $rows),
            'previous' => $page > 1 ? $base . '?page=' . ($page - 1) : null,
            'next' => $page * self::PER_PAGE < $total ? $base . '?page=' . ($page + 1) : null,
            'forum_options' => ForumOptions::readable($this->forums, $this->visibility->readable($subject)),
            'form' => $form,
            'max_reason' => EditPost::MAX_REASON_LENGTH,
            'error' => $error,
        ]), $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** A thread ID typed as a number or as a thread address (`/threads/{id}`, any page or post anchor). */
    public static function threadId(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('~^[1-9][0-9]{0,17}$~D', $value) === 1) {
            return (int) $value;
        }

        return preg_match('~/threads/([1-9][0-9]{0,17})(?:[/?#]|$)~', $value, $match) === 1 ? (int) $match[1] : null;
    }
}
