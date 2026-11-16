<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Moderation\Domain\ResolveReport;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST `/moderation/reports/{report}` (M04.07, CSRF-checked by the kernel guard): resolve or dismiss with a
 * note, then back to the queue. Reports outside the moderator's forums are a plain 404.
 */
final class ReportDecisionHandler implements RouteHandler
{
    private const STATUS = [
        'moderation_denied' => Response::HTTP_FORBIDDEN,
        'report_not_open' => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ResolveReport $decisions,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $input = $request->request->all();
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        $note = is_string($input['note'] ?? null) ? $input['note'] : '';
        $queue = $this->urls->generate(CoreRoutes::MODERATION_QUEUE) . '?tab=' . ModerationQueueHandler::REPORTS;

        try {
            $this->decisions->decide($this->subjects->of($request), (int) ($parameters['report'] ?? 0), $action, $note);
        } catch (ThreadRuleViolation $violation) {
            if ($violation->diagnostic === 'report_not_found') {
                return $this->errors->notFound();
            }

            $html = $this->views->render('moderation/decision.twig', [
                'title' => $this->translator->translate('moderation.decision.title'),
                'error' => $this->translator->translate(
                    'diagnostics.' . $violation->diagnostic,
                    $violation->parameters,
                ),
                'queue_url' => $queue,
            ]);

            return new Response($html, self::STATUS[$violation->diagnostic] ?? Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return InstallerPage::redirect($queue);
    }
}
