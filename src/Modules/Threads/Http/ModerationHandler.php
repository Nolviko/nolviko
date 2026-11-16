<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use InvalidArgumentException;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delete, restore or purge one post or thread (M03.07). GET shows a confirmation form with an optional reason;
 * POST (CSRF-checked by the kernel guard) applies `action` through ModerateContent. One instance serves posts,
 * another threads. Guests are sent to the login page; content the subject may not see is a plain 404.
 */
final class ModerationHandler implements RouteHandler
{
    public const POST = 'post';

    public const THREAD = 'thread';

    private const STATUS = [
        'moderation_denied' => Response::HTTP_FORBIDDEN,
        'thread_locked' => Response::HTTP_FORBIDDEN,
        'content_already_deleted' => Response::HTTP_CONFLICT,
        'content_not_deleted' => Response::HTTP_CONFLICT,
        'content_not_pending' => Response::HTTP_CONFLICT,
    ];

    private const NOT_FOUND = ['post_not_found', 'thread_not_found'];

    private const TITLES = [
        self::POST => 'threads.moderate.title.post',
        self::THREAD => 'threads.moderate.title.thread',
    ];

    /**
     * @throws InvalidArgumentException When $target is neither POST nor THREAD.
     */
    public function __construct(
        private readonly string $target,
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ModerateContent $moderation,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
        if ($target !== self::POST && $target !== self::THREAD) {
            throw new InvalidArgumentException('moderation_target_invalid');
        }
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $id = (int) ($parameters[$this->target] ?? 0);
        $input = $request->request->all();
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';

        if (!$request->isMethod('POST')) {
            return $this->page($id, null, '');
        }

        try {
            $subject = $this->subjects->of($request);
            $this->target === self::POST
                ? $this->moderation->post($subject, $id, $action, $reason)
                : $this->moderation->thread($subject, $id, $action, $reason);
        } catch (ThreadRuleViolation $violation) {
            return in_array($violation->diagnostic, self::NOT_FOUND, true)
                ? $this->errors->notFound()
                : $this->page($id, $violation, $reason);
        }

        // Back to what was moderated; moderators see hidden and pending content there.
        return InstallerPage::redirect($this->target === self::POST
            ? $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $id])
            : $this->urls->generate(CoreRoutes::THREAD_ID, ['thread' => (string) $id]));
    }

    private function page(int $id, ?ThreadRuleViolation $violation, string $reason): Response
    {
        $parameters = $violation?->diagnostic === 'post_reason_invalid' ? ['max' => EditPost::MAX_REASON_LENGTH] : [];
        $html = $this->views->render('threads/moderate.twig', [
            'title' => $this->translator->translate(self::TITLES[$this->target]),
            'action' => $this->urls->generate(
                $this->target === self::POST ? CoreRoutes::POST_MODERATE : CoreRoutes::THREAD_MODERATE,
                [$this->target => (string) $id],
            ),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'choices' => [
                'delete' => ModerateContent::DELETE,
                'restore' => ModerateContent::RESTORE,
                'purge' => ModerateContent::PURGE,
                'approve' => ModerateContent::APPROVE,
            ],
            'reason' => $reason,
            'max_reason' => EditPost::MAX_REASON_LENGTH,
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
