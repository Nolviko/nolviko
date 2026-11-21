<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
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
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\DraftContext;
use Nolviko\Modules\Threads\Domain\Drafts;
use Nolviko\Modules\Threads\Domain\Thread;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Http\ReturnPath;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET shows the reply form of a thread with a fresh idempotency token; POST (CSRF-checked by the kernel guard)
 * replies through CreateReply. A repeated submit of the same form lands where the first one did. Guests are sent
 * to the login page; a thread the subject may not read is a plain 404.
 */
final class CreateReplyHandler implements RouteHandler
{
    /** Posts one multi-quote link may carry. */
    public const MAX_QUOTES = 10;

    /** The value of the form's "Save draft" button (M03.14). */
    public const DRAFT = 'draft';

    public const TOKEN_FIELD = 'token';

    /** Set after a successful reply (beta.10): draft-keeper.js drops the browser copy of that form. */
    public const POSTED_COOKIE = 'nolviko_posted';

    private const STATUS = [
        'reply_permission_denied' => Response::HTTP_FORBIDDEN,
        'thread_locked' => Response::HTTP_FORBIDDEN,
        'post_flood_wait' => Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly CreateReply $replies,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly ?Drafts $drafts = null,
        private readonly ?Closure $clock = null,
        private readonly ?PostRepository $posts = null,
        private readonly ?UserRepository $users = null,
        private readonly ?ThreadRepository $threads = null,
        private readonly int $perPage = ThreadPageHandler::DEFAULT_PER_PAGE,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            // Back to this reply page after signing in (beta.10); draft-keeper.js still holds the typed text.
            return InstallerPage::redirect(ReturnPath::append(
                $this->urls->generate(CoreRoutes::LOGIN),
                ReturnPath::safe($request->getBasePath() . $request->getPathInfo(), $request->getBasePath()),
            ));
        }

        $subject = $this->subjects->of($request);
        $threadId = (int) ($parameters['thread'] ?? 0);

        try {
            $thread = $this->replies->authorize($subject, $threadId);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic === 'thread_not_found'
                ? $this->errors->notFound()
                : $this->page(null, $violation, CreateReply::newToken(), '');
        }

        $userId = (int) $subject->userId;
        $context = DraftContext::reply($threadId);

        if (!$request->isMethod('POST')) {
            $draft = $this->drafts?->find($userId, $context);
            $quote = $this->quotes($threadId, $request->query->get('quote'));

            // A quote is added after a restored draft, so following a "Quote" link never loses saved text.
            return $draft === null
                ? $this->page($thread, null, CreateReply::newToken(), $quote)
                : $this->page(
                    $thread,
                    null,
                    CreateReply::newToken(),
                    $quote === '' ? $draft['content'] : rtrim($draft['content']) . "\n\n" . $quote,
                    'threads.draft.restored',
                );
        }

        $input = $request->request->all();
        $token = is_string($input[self::TOKEN_FIELD] ?? null) ? $input[self::TOKEN_FIELD] : '';
        $content = is_string($input['content'] ?? null) ? $input['content'] : '';

        // Markdown chosen in the composer (2026-11-08) is stored as the equivalent BBCode.
        if (($input['format'] ?? '') === MarkdownToBbcode::FORMAT) {
            $content = MarkdownToBbcode::convert($content);
        }

        if (ComposerPreview::requested($input)) {
            $preview = ComposerPreview::html($this->translator, $content);

            return $preview === null
                ? $this->page($thread, new ThreadRuleViolation('post_content_invalid'), $token, $content)
                : $this->page($thread, null, $token, $content, preview: $preview);
        }

        // M03.14: "Save draft" keeps the text for this thread and shows the form again; nothing is posted.
        if ($this->drafts !== null && ($input['intent'] ?? '') === self::DRAFT) {
            if (mb_strlen($content) > Drafts::MAX_CONTENT || !mb_check_encoding($content, 'UTF-8')) {
                return $this->page($thread, new ThreadRuleViolation('post_content_invalid'), $token, $content);
            }

            $this->drafts->save($userId, $context, null, $content, $this->now());

            return $this->page($thread, null, $token, $content, 'threads.draft.saved');
        }

        try {
            $created = $this->replies->reply($subject, $threadId, $token, $content, $request->getClientIp());
        } catch (ThreadRuleViolation $violation) {
            // A rejected submit wrote nothing, so the same token stays valid; an invalid one is replaced.
            $retry = $violation->diagnostic === 'post_token_invalid' ? CreateReply::newToken() : $token;

            return $this->page($thread, $violation, $retry, $content);
        }

        $this->drafts?->discard($userId, $context);

        // The new reply (its permalink finds the right page), or the thread while the reply waits for approval.
        $response = InstallerPage::redirect($created->status === PostStatus::Visible
            ? $this->postLocation($created->postId)
            : $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $threadId]));
        // Tells draft-keeper.js that this form's text was posted, so its browser copy is dropped (beta.10). The
        // value is the form's one-time idempotency token, not a secret.
        $response->headers->setCookie(Cookie::create(
            self::POSTED_COOKIE,
            $token,
            time() + 300,
            $request->getBasePath() === '' ? '/' : $request->getBasePath(),
            null,
            $request->isSecure(),
            false,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * Multi-quote (2026-11-08): `?quote=1,2,3` (at most MAX_QUOTES posts, in the given order); anything else that
     * is not a list of IDs gives an empty form.
     */
    private function quotes(int $threadId, mixed $raw): string
    {
        $raw = is_scalar($raw) ? (string) $raw : '';

        if (preg_match('/^[1-9][0-9]{0,17}(?:,[1-9][0-9]{0,17}){0,' . (self::MAX_QUOTES - 1) . '}$/D', $raw) !== 1) {
            return '';
        }

        $quotes = array_map(
            fn(string $id): string => $this->quote($threadId, (int) $id),
            array_values(array_unique(explode(',', $raw))),
        );

        return implode("\n", array_filter($quotes, static fn(string $quote): bool => $quote !== ''));
    }

    /**
     * "Quote" link (`?quote={post}`): the source of a visible post of this very thread wrapped in [quote=author].
     * The reader already passed the reply authorization for this thread, so nothing beyond what the thread page
     * shows is revealed; another thread's, a pending or a hidden post gives an empty form instead.
     */
    private function quote(int $threadId, int $postId): string
    {
        $post = $postId > 0 ? $this->posts?->find($postId) : null;

        if ($post === null || $post->threadId !== $threadId || $post->status !== PostStatus::Visible) {
            return '';
        }

        $author = $post->userId === null ? null : $this->users?->findById($post->userId)?->username;
        $name = $author === null ? '' : '=' . str_replace(['[', ']'], '', $author);

        return '[quote' . $name . ']' . trim($post->content) . "[/quote]\n";
    }

    /**
     * The new reply's page and anchor, computed as PostPermalinkHandler does (2026-11-21 benchmark): the browser no
     * longer makes a second full request through the permalink. Falls back to the permalink when a lookup is not
     * available.
     */
    private function postLocation(int $postId): string
    {
        $permalink = $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $postId]);
        $post = $this->posts?->find($postId);
        $thread = $post === null ? null : $this->threads?->find($post->threadId);

        if ($post === null || $thread === null) {
            return $permalink;
        }

        $total = ThreadPageHandler::visibleTotal($thread);
        $before = $this->posts->visibleBefore($post, $total, $thread->firstPostId, $thread->lastPostId);
        $page = min(ThreadPageHandler::pageCount($total, $this->perPage), intdiv($before, $this->perPage) + 1);

        return ThreadPageHandler::url($this->urls, $thread->id, $page, $thread->slug) . '#post-' . $post->id;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)();
    }

    /**
     * @param Thread|null $thread Null when the form must not be shown (refused before any input).
     */
    private function page(
        ?Thread $thread,
        ?ThreadRuleViolation $violation,
        string $token,
        string $content,
        ?string $notice = null,
        ?SafeHtml $preview = null,
    ): Response {
        $parameters = $violation?->parameters ?? [];
        $reason = $parameters['extension_reason'] ?? null;
        $errorId = is_string($reason) && $this->translator->has($reason)
            ? $reason : 'diagnostics.' . ($violation?->diagnostic ?? 'post_content_blocked');

        if ($violation?->diagnostic === 'post_content_invalid') {
            $parameters['max'] = CreateThread::MAX_CONTENT_LENGTH;
        }

        $html = $this->views->render('threads/reply.twig', [
            'title' => $this->translator->translate('threads.reply.title'),
            'thread_title' => $thread?->title,
            'thread_id' => $thread?->id,
            'action' => $thread === null
                ? null
                : $this->urls->generate(CoreRoutes::THREAD_REPLY, ['thread' => (string) $thread->id]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'token_field' => self::TOKEN_FIELD,
            'token' => $token,
            'content' => $content,
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
