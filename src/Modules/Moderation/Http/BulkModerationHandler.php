<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Moderation\Domain\BulkModeration;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST `/moderation/bulk` (M04.08, CSRF-checked by the kernel guard): `target`, `action`, `ids[]`, `reason` and,
 * for moves, `forum`. Shows what happened to every item; a request refused as a whole is 422 (403 for
 * `moderation_denied`). Guests go to the login.
 */
final class BulkModerationHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly BulkModeration $bulk,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $input = $request->request->all();
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        $ids = array_map(
            'intval',
            array_filter($ids, static fn(mixed $id): bool => is_string($id) && ctype_digit($id)),
        );
        $forum = is_string($input['forum'] ?? null) && ctype_digit($input['forum']) ? (int) $input['forum'] : null;
        $status = Response::HTTP_OK;
        $error = null;
        $results = [];

        try {
            $results = $this->bulk->apply(
                $this->subjects->of($request),
                is_string($input['target'] ?? null) ? $input['target'] : '',
                is_string($input['action'] ?? null) ? $input['action'] : '',
                array_values($ids),
                is_string($input['reason'] ?? null) ? $input['reason'] : '',
                $forum,
            );
        } catch (ThreadRuleViolation $violation) {
            $error = $this->translator->translate('diagnostics.' . $violation->diagnostic, $violation->parameters);
            $status = $violation->diagnostic === 'moderation_denied'
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $html = $this->views->render('moderation/bulk.twig', [
            'title' => $this->translator->translate('moderation.bulk.title'),
            'error' => $error,
            'results' => array_map(
                fn(?string $diagnostic): string => $diagnostic === null
                    ? $this->translator->translate('moderation.bulk.done')
                    : $this->translator->translate('diagnostics.' . $diagnostic),
                $results,
            ),
            'applied' => count(array_filter($results, static fn(?string $diagnostic): bool => $diagnostic === null)),
            'queue_url' => $this->urls->generate(CoreRoutes::MODERATION_QUEUE),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
