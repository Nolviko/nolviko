<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/posts/{post}/report` (M04.07a): GET shows the form, POST (CSRF-checked by the kernel guard) files the report
 * and goes back to the post. Guests are sent to the login page; a post the reporter cannot read is a plain 404.
 */
final class ReportHandler implements RouteHandler
{
    private const STATUS = [
        'report_denied' => Response::HTTP_FORBIDDEN,
        'report_own_post' => Response::HTTP_FORBIDDEN,
        'report_exists' => Response::HTTP_CONFLICT,
        'report_flood_wait' => Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ReportPost $reports,
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
        $reason = $request->request->all()['reason'] ?? '';
        $reason = is_string($reason) ? $reason : '';

        try {
            if (!$request->isMethod('POST')) {
                $this->reports->authorize($subject, $postId);

                return $this->page($postId, null, '');
            }

            $this->reports->report($subject, $postId, $reason);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic === 'post_not_found'
                ? $this->errors->notFound()
                : $this->page($postId, $violation, $reason);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $postId]));
    }

    private function page(int $postId, ?ThreadRuleViolation $violation, string $reason): Response
    {
        $parameters = $violation?->parameters ?? [];
        $html = $this->views->render('moderation/report.twig', [
            'title' => $this->translator->translate('moderation.report.title'),
            'action' => $this->urls->generate(CoreRoutes::POST_REPORT, ['post' => (string) $postId]),
            'post_url' => $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $postId]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'reason' => $reason,
            'max_reason' => ReportPost::MAX_REASON_LENGTH,
            'error' => $violation === null
                ? null
                : $this->translator->translate('diagnostics.' . $violation->diagnostic, $parameters),
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
