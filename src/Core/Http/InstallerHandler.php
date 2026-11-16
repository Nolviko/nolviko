<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Installer\PrerequisiteCheck;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installer step 1: the prerequisite screen (M01.13). Reachable only while not installed (InstallationGuard).
 */
final class InstallerHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly PrerequisiteCheck $prerequisites,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $html = $this->views->render('installer/prerequisites.twig', [
            'language' => str_replace('_', '-', $this->translator->locale()),
            'title' => $this->translator->translate('core.installer.title'),
            'report' => $this->prerequisites->run($request),
            'recheck_url' => $this->urls->generate(CoreRoutes::INSTALLER_START),
            'database_url' => $this->urls->generate(CoreRoutes::INSTALLER_DATABASE),
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
