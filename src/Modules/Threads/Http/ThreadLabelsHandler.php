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
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/threads/{thread}/labels` (2026-11-08): a moderator of the forum changes the thread's prefix and tags
 * (audited). Authors choose them when they start the thread.
 */
final readonly class ThreadLabelsHandler implements RouteHandler
{
    public function __construct(
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private RequestSubject $subjects,
        private ThreadRepository $threads,
        private ThreadTaxonomy $taxonomy,
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

        if (!$subject->allows(ModerateContent::MODERATE, $thread->forumId)) {
            return $this->errors->forbidden();
        }

        $threadUrl = $this->urls->generate(
            CoreRoutes::THREAD_SHOW,
            ['slug' => $thread->slug, 'thread' => (string) $thread->id],
        );
        $error = null;
        [$prefix, $tags] = self::picked($request->request->all());

        if ($request->isMethod('POST')) {
            try {
                $this->taxonomy->assign($thread->id, $thread->forumId, $prefix, $tags, (int) $subject->userId);

                return InstallerPage::redirect($threadUrl);
            } catch (ThreadRuleViolation $violation) {
                $error = $this->translator->translate('diagnostics.' . $violation->diagnostic, $violation->parameters);
            }
        } else {
            $current = $this->taxonomy->forThreads([$thread->id])[$thread->id] ?? ['prefix' => null, 'tags' => []];
            $prefix = $current['prefix']['id'] ?? null;
            $tags = array_map(static fn(array $tag): int => $tag['id'], $current['tags']);
        }

        return new Response($this->views->render('threads/labels.twig', [
            'title' => $this->translator->translate('threads.labels.title', ['thread' => $thread->title]),
            'thread_url' => $threadUrl,
            'action' => $this->urls->generate(CoreRoutes::THREAD_LABELS, ['thread' => (string) $thread->id]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'prefix_options' => $this->taxonomy->prefixes($thread->forumId),
            'tag_options' => $this->taxonomy->tags(),
            'selected_prefix' => $prefix,
            'selected_tags' => $tags,
            'max_tags' => ThreadTaxonomy::MAX_TAGS,
            'error' => $error,
        ]), $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The prefix and tag IDs of a submitted form (the create-thread form uses the same fields).
     *
     * @param array<string, mixed> $input
     * @return array{int|null, list<int>}
     */
    public static function picked(array $input): array
    {
        $prefix = is_string($input['prefix'] ?? null) && ctype_digit($input['prefix']) && $input['prefix'] !== '0'
            ? (int) $input['prefix'] : null;
        $tags = array_values(array_map(intval(...), array_filter(
            is_array($input['tags'] ?? null) ? $input['tags'] : [],
            static fn(mixed $id): bool => is_string($id) && ctype_digit($id),
        )));

        return [$prefix, $tags];
    }
}
