<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Http;

use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Importers\Infrastructure\ImportJobs;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin > Import an old forum (ADR-038, 2026-11-20). Imports run in the official extension `nolviko/importer`: while
 * it is enabled this page forwards to its screen; otherwise it explains how to install it and lists earlier imports.
 */
final readonly class ImportsHandler implements RouteHandler
{
    public const EXTENSION = 'nolviko/importer';

    public const EXTENSION_ROUTE = 'nolviko.importer.admin';

    public function __construct(
        private ImportJobs $jobs,
        private ExtensionManager $extensions,
        private RequestSubject $subjects,
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows(AdminGuard::PERMISSION)) {
            return new Response('', 403, ['Cache-Control' => 'private, no-store']);
        }
        try {
            if (isset($this->extensions->state->enabled()[self::EXTENSION]) && !$this->extensions->state->safe()) {
                return InstallerPage::redirect($this->urls->generate(self::EXTENSION_ROUTE));
            }
        } catch (Throwable) {
            // The extension's route is not registered (safe mode, failed boot): show the page below.
        }
        try {
            $recent = $this->jobs->recent();
        } catch (Throwable) {
            $recent = [];
        }
        return new Response($this->views->render('admin/imports.twig', [
            'title' => $this->translator->translate('operations.imports'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_IMPORTS),
            'extensions_url' => $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS),
            'recent' => $recent,
        ]), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer']);
    }
}
