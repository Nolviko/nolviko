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
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Editor\Domain\MarkdownToBbcode;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET shows the edit form of a post with its current source; POST (CSRF-checked by the kernel guard) stores the
 * edit through EditPost. Guests are sent to the login page; a post the subject may not read is a plain 404, one
 * they may not edit (someone else's, locked thread, window over) a 403 page without the form.
 */
final class EditPostHandler implements RouteHandler
{
    private const STATUS = [
        'post_edit_denied' => Response::HTTP_FORBIDDEN,
        'post_edit_window_expired' => Response::HTTP_FORBIDDEN,
        'thread_locked' => Response::HTTP_FORBIDDEN,
    ];

    private const LIMITS = [
        'post_content_invalid' => CreateThread::MAX_CONTENT_LENGTH,
        'post_reason_invalid' => EditPost::MAX_REASON_LENGTH,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly EditPost $edits,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $subject = $this->subjects->of($request);
        $postId = (int) ($parameters['post'] ?? 0);

        try {
            $post = $this->edits->authorize($subject, $postId);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic === 'post_not_found'
                ? $this->errors->notFound()
                : $this->page(null, $violation, '', '');
        }

        if (!$request->isMethod('POST')) {
            return $this->page($post->id, null, $post->content, '', title: $this->edits->threadTitle($post->id));
        }

        $input = $request->request->all();
        $content = is_string($input['content'] ?? null) ? $input['content'] : '';

        // Markdown chosen in the composer (2026-11-08) is stored as the equivalent BBCode.
        if (($input['format'] ?? '') === MarkdownToBbcode::FORMAT) {
            $content = MarkdownToBbcode::convert($content);
        }
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';

        if (ComposerPreview::requested($input)) {
            $preview = ComposerPreview::html($this->translator, $content);

            return $preview === null
                ? $this->page($post->id, new ThreadRuleViolation('post_content_invalid'), $content, $reason)
                : $this->page($post->id, null, $content, $reason, $preview);
        }

        try {
            $title = $input['title'] ?? null;

            if (is_string($title) && $this->edits->threadTitle($postId) !== null) {
                $this->edits->retitle($subject, $postId, $title);
            }

            $this->edits->edit($subject, $postId, $content, $reason);
        } catch (ThreadRuleViolation $violation) {
            return $this->page($post->id, $violation, $content, $reason);
        }

        // Back to the edited post; its permalink finds the right thread page.
        return InstallerPage::redirect($this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $postId]));
    }

    /**
     * @param int|null $postId Null when the form must not be shown (refused before any input).
     */
    private function page(
        ?int $postId,
        ?ThreadRuleViolation $violation,
        string $content,
        string $reason,
        ?SafeHtml $preview = null,
        ?string $title = null,
    ): Response {
        $parameters = $violation?->parameters ?? [];

        if ($violation !== null && isset(self::LIMITS[$violation->diagnostic])) {
            $parameters['max'] = self::LIMITS[$violation->diagnostic];
        }

        $html = $this->views->render('threads/edit.twig', [
            'title' => $this->translator->translate('threads.edit.title'),
            'action' => $postId === null
                ? null
                : $this->urls->generate(CoreRoutes::POST_EDIT, ['post' => (string) $postId]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'content' => $content,
            'reason' => $reason,
            'thread_title' => $title ?? ($postId === null ? null : $this->edits->threadTitle($postId)),
            'max_title' => CreateThread::MAX_TITLE_LENGTH,
            'max_content' => CreateThread::MAX_CONTENT_LENGTH,
            'max_reason' => EditPost::MAX_REASON_LENGTH,
            'preview_intent' => ComposerPreview::INTENT,
            'preview' => $preview,
            'error' => $violation === null
                ? null
                : $this->translator->translate('diagnostics.' . $violation->diagnostic, $parameters),
        ]);
        $status = $violation === null
            ? Response::HTTP_OK
            : self::STATUS[$violation->diagnostic] ?? Response::HTTP_UNPROCESSABLE_ENTITY;

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
