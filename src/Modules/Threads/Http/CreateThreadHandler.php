<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Calendar\Infrastructure\CalendarEvents;
use Nolviko\Modules\Content\Infrastructure\ContentLibrary;
use Nolviko\Modules\Editor\Domain\MarkdownToBbcode;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\DraftContext;
use Nolviko\Modules\Threads\Domain\Drafts;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET shows the new-thread form of a forum; POST (CSRF-checked by the kernel guard) creates the thread through
 * CreateThread. Guests are sent to the login page. A forum the subject may not see is a plain 404; other
 * refusals re-show the page with the translated reason, keeping what the user typed.
 */
final class CreateThreadHandler implements RouteHandler
{
    /** The value of the form's "Save draft" button (M03.14). */
    public const DRAFT = 'draft';

    private const STATUS = [
        'thread_permission_denied' => Response::HTTP_FORBIDDEN,
        'thread_forum_locked' => Response::HTTP_FORBIDDEN,
        'post_flood_wait' => Response::HTTP_TOO_MANY_REQUESTS,
    ];

    private const NOT_FOUND = ['thread_forum_not_found', 'thread_forum_is_category'];

    /** The `max` parameter of the input diagnostics. */
    private const LIMITS = [
        'thread_title_invalid' => CreateThread::MAX_TITLE_LENGTH,
        'post_content_invalid' => CreateThread::MAX_CONTENT_LENGTH,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly CreateThread $threads,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly ?Drafts $drafts = null,
        private readonly ?Closure $clock = null,
        private readonly ?ThreadTaxonomy $taxonomy = null,
        private readonly ?ContentLibrary $content = null,
        private readonly ?Connection $db = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $subject = $this->subjects->of($request);
        $forumId = (int) ($parameters['forum'] ?? 0);

        try {
            $forum = $this->threads->authorize($subject, $forumId);
        } catch (ThreadRuleViolation $violation) {
            return in_array($violation->diagnostic, self::NOT_FOUND, true)
                ? $this->errors->notFound()
                : $this->page(null, $violation, '', '');
        }

        $userId = (int) $subject->userId;
        $context = DraftContext::thread($forumId);

        if (!$request->isMethod('POST')) {
            $draft = $this->drafts?->find($userId, $context);

            return $draft === null
                ? $this->page($forum, null, '', '')
                : $this->page($forum, null, $draft['title'] ?? '', $draft['content'], 'threads.draft.restored');
        }

        $input = $request->request->all();
        $title = is_string($input['title'] ?? null) ? $input['title'] : '';
        $content = is_string($input['content'] ?? null) ? $input['content'] : '';

        // Markdown chosen in the composer (2026-11-08) is stored as the equivalent BBCode.
        if (($input['format'] ?? '') === MarkdownToBbcode::FORMAT) {
            $content = MarkdownToBbcode::convert($content);
        }

        if (ComposerPreview::requested($input)) {
            $preview = ComposerPreview::html($this->translator, $content);

            return $preview === null
                ? $this->page($forum, new ThreadRuleViolation('post_content_invalid'), $title, $content)
                : $this->page(
                    $forum,
                    null,
                    $title,
                    $content,
                    preview: $preview,
                    picked: ThreadLabelsHandler::picked($input)
                );
        }

        // M03.14: "Save draft" keeps title and text for this forum and shows the form again; nothing is posted.
        if ($this->drafts !== null && ($input['intent'] ?? '') === self::DRAFT) {
            $valid = mb_check_encoding($title . $content, 'UTF-8')
                && mb_strlen($content) <= Drafts::MAX_CONTENT
                && mb_strlen($title) <= CreateThread::MAX_TITLE_LENGTH;

            if (!$valid) {
                return $this->page($forum, new ThreadRuleViolation('post_content_invalid'), $title, $content);
            }

            $this->drafts->save($userId, $context, $title, $content, $this->now());

            return $this->page($forum, null, $title, $content, 'threads.draft.saved');
        }

        [$prefix, $tags] = ThreadLabelsHandler::picked($input);

        try {
            [$prefix, $tags] = $this->taxonomy?->validate($forumId, $prefix, $tags) ?? [null, []];
            $created = $this->threads->create($subject, $forumId, $title, $content, $request->getClientIp());
        } catch (ThreadRuleViolation $violation) {
            return $this->page($forum, $violation, $title, $content, picked: [$prefix, $tags]);
        }

        if ($this->taxonomy !== null && ($prefix !== null || $tags !== [])) {
            $this->taxonomy->assign($created->threadId, $forumId, $prefix, $tags);
        }

        // beta.18 roadmap 29: an administrator-defined thread icon, chosen from the library only.
        $icon = $this->content?->pickIcon($input['icon'] ?? null);

        if ($icon !== null && $this->db !== null) {
            $this->db->update('threads', ['icon_id' => $icon], ['id' => $created->threadId]);
        }

        // beta.18 roadmap 38: an optional calendar date (range) for the new thread.
        $start = is_string($input['event_start'] ?? null) ? $input['event_start'] : '';

        if ($start !== '' && $this->db !== null) {
            (new CalendarEvents($this->db))->set(
                $created->threadId,
                $userId,
                $start,
                is_string($input['event_end'] ?? null) ? $input['event_end'] : '',
            );
        }

        $this->drafts?->discard($userId, $context);

        // The new thread, or its forum while it waits for approval (a pending thread is not shown to its author).
        return InstallerPage::redirect($created->status === ThreadStatus::Visible
            ? $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $created->threadId])
            : $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forumId]));
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)();
    }

    /**
     * @param Forum|null $forum Null when the form must not be shown (refused before any input).
     */
    private function page(
        ?Forum $forum,
        ?ThreadRuleViolation $violation,
        string $title,
        string $content,
        ?string $notice = null,
        ?SafeHtml $preview = null,
        array $picked = [null, []],
    ): Response {
        $parameters = $violation?->parameters ?? [];
        $reason = $parameters['extension_reason'] ?? null;
        $errorId = is_string($reason) && $this->translator->has($reason)
            ? $reason : 'diagnostics.' . ($violation?->diagnostic ?? 'post_content_blocked');

        if ($violation !== null && isset(self::LIMITS[$violation->diagnostic])) {
            $parameters['max'] = self::LIMITS[$violation->diagnostic];
        }

        $html = $this->views->render('threads/create.twig', [
            'title' => $this->translator->translate('threads.create.title'),
            'forum_title' => $forum?->title,
            'action' => $forum === null
                ? null
                : $this->urls->generate(CoreRoutes::THREAD_CREATE, ['forum' => (string) $forum->id]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'thread_title' => $title,
            'prefix_options' => $forum === null ? [] : $this->taxonomy?->prefixes($forum->id) ?? [],
            'icon_options' => array_values($this->content?->icons() ?? []),
            'tag_options' => $forum === null ? [] : $this->taxonomy?->tags() ?? [],
            'selected_prefix' => $picked[0],
            'selected_tags' => $picked[1],
            'max_tags' => ThreadTaxonomy::MAX_TAGS,
            'content' => $content,
            'max_title' => CreateThread::MAX_TITLE_LENGTH,
            'max_content' => CreateThread::MAX_CONTENT_LENGTH,
            'draft_intent' => $this->drafts === null ? null : self::DRAFT,
            'preview_intent' => ComposerPreview::INTENT,
            'preview' => $preview,
            'notice' => $notice === null ? null : $this->translator->translate($notice),
            'error' => $violation === null
                ? null
                : $this->translator->translate($errorId, $parameters),
        ]);
        $status = $violation === null
            ? Response::HTTP_OK
            : self::STATUS[$violation->diagnostic] ?? Response::HTTP_UNPROCESSABLE_ENTITY;
        $response = new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);

        if (isset($parameters['seconds'])) {
            $response->headers->set('Retry-After', (string) $parameters['seconds']);
        }

        return $response;
    }
}
