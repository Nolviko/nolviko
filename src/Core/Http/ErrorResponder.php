<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Runtime\DiagnosticMessage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Builds the translated system error responses. Never exposes exception details.
 *
 * When no translator is available (for example intl is missing during a 503), or translating fails, the
 * page falls back to the numeric status code and raw diagnostic IDs: still safe, never a PHP fatal error.
 */
final class ErrorResponder
{
    private const NO_STORE = 'no-store';

    /**
     * @param (Closure(): string)|null $home The home page address; null guesses it from the script path (early
     *     failures have no router).
     */
    public function __construct(
        private readonly ?MessageTranslator $translator,
        private readonly ?Closure $home = null,
    ) {
    }

    public function badRequest(): Response
    {
        return $this->page(Response::HTTP_BAD_REQUEST, 'core.error.bad_request.title', 'core.error.bad_request.body');
    }

    /**
     * A state-changing request without a valid CSRF token or from another origin (CsrfGuard).
     */
    public function forbidden(): Response
    {
        return $this->page(Response::HTTP_FORBIDDEN, 'core.error.forbidden.title', 'core.error.forbidden.body');
    }

    /**
     * A signed-in user without the permission a page needs (AdminGuard; M02.15).
     */
    public function denied(): Response
    {
        return $this->page(Response::HTTP_FORBIDDEN, 'core.error.denied.title', 'core.error.denied.body');
    }

    public function maintenance(): Response
    {
        $response = $this->page(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'core.maintenance.title',
            'core.maintenance.body',
        );
        $response->headers->set('Retry-After', '300');

        return $response;
    }

    public function notFound(): Response
    {
        return $this->page(Response::HTTP_NOT_FOUND, 'core.error.not_found.title', 'core.error.not_found.body');
    }

    /**
     * @param list<string> $allowedMethods
     */
    public function methodNotAllowed(array $allowedMethods): Response
    {
        $response = $this->page(
            Response::HTTP_METHOD_NOT_ALLOWED,
            'core.error.method_not_allowed.title',
            'core.error.method_not_allowed.body',
        );
        $response->headers->set('Allow', implode(', ', $allowedMethods));

        return $response;
    }

    public function serverError(string $incidentId): Response
    {
        return $this->page(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'core.error.server.title',
            'core.error.server.body',
            ['incident' => $incidentId],
            fallbackItems: [$incidentId],
        );
    }

    /**
     * @param list<string> $diagnostics Runtime diagnostic IDs, e.g. `extension_missing:intl`.
     */
    public function unavailable(array $diagnostics): Response
    {
        $items = array_map($this->describeDiagnostic(...), $diagnostics);
        $count = $this->text('core.error.unavailable.count', ['count' => count($diagnostics)]);

        return $this->page(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'core.error.unavailable.title',
            'core.error.unavailable.body',
            extraParagraphs: $count === null ? [] : [$count],
            items: $items,
        );
    }

    /**
     * @param array<string, string> $bodyParameters
     * @param list<string> $extraParagraphs
     * @param list<string> $items
     * @param list<string> $fallbackItems Shown instead of $items when nothing could be translated.
     */
    private function page(
        int $status,
        string $titleId,
        string $bodyId,
        array $bodyParameters = [],
        array $extraParagraphs = [],
        array $items = [],
        array $fallbackItems = [],
    ): Response {
        $title = $this->text($titleId);
        $body = $this->text($bodyId, $bodyParameters);

        if ($title === null || $body === null) {
            $html = HtmlPage::render(
                'en',
                (string) $status,
                [],
                $items === [] ? $fallbackItems : $items,
                homeUrl: $this->homeUrl(),
                homeLabel: 'Home', // i18n-ignore: catalog unavailable, English fallback page.
            );
        } else {
            $html = HtmlPage::render(
                $this->language(),
                $title,
                [$body, ...$extraParagraphs],
                $items,
                $status,
                $this->homeUrl(),
                $this->text('core.error.home') ?? '',
            );
        }

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => self::NO_STORE,
        ]);
    }

    /** Where the logo and the home link lead (2026-11-14). */
    private function homeUrl(): string
    {
        try {
            if ($this->home !== null) {
                return ($this->home)();
            }
        } catch (Throwable) {
            // Fall back to the guess below.
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $directory = rtrim(dirname($script), '/');

        return (str_ends_with($directory, '/public') ? substr($directory, 0, -strlen('/public')) : $directory) . '/';
    }

    private function describeDiagnostic(string $diagnostic): string
    {
        [$id, $parameters] = DiagnosticMessage::fromDiagnostic($diagnostic);

        return $this->text($id, $parameters) ?? $diagnostic;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function text(string $id, array $parameters = []): ?string
    {
        if ($this->translator === null) {
            return null;
        }

        try {
            return $this->translator->translate($id, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    private function language(): string
    {
        return str_replace('_', '-', $this->translator?->locale() ?? 'en');
    }
}
