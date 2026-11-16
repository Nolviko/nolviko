<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Runtime\DiagnosticMessage;
use Nolviko\Core\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared rendering for installer steps: page language and title, translated diagnostics, no-store headers.
 */
final class InstallerPage
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        $html = $this->views->render($template, $context + [
            'language' => str_replace('_', '-', $this->translator->locale()),
            'title' => $this->translator->translate('core.installer.title'),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param list<string> $diagnostics IDs such as `installer_field_missing:host`.
     *
     * @return list<string> Translated texts.
     */
    public function messages(array $diagnostics): array
    {
        return array_map(function (string $diagnostic): string {
            [$id, $parameters] = DiagnosticMessage::fromDiagnostic($diagnostic);

            return $this->translator->has($id) ? $this->translator->translate($id, $parameters) : $diagnostic;
        }, $diagnostics);
    }

    public static function redirect(string $location): Response
    {
        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }
}
