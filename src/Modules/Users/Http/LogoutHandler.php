<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST only (CSRF-checked): logs out; `everywhere=1` also revokes every remember-me token of the account.
 */
final class LogoutHandler implements RouteHandler
{
    public function __construct(
        private readonly AuthSession $auth,
        private readonly UrlGenerator $urls,
        private readonly ?TemplateRenderer $views = null,
        private readonly ?CsrfTokens $csrf = null,
        private readonly ?MessageTranslator $translator = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        // GET (2026-11-14): a confirmation with a fresh form. A link or an expired form never logs anyone out by
        // itself, but the member always has a working way out.
        if (
            !$request->isMethod('POST') && $this->views !== null && $this->csrf !== null
            && $this->translator !== null
        ) {
            return new Response($this->views->render('auth/logout.twig', [
                'title' => $this->translator->translate('auth.logout.title'),
                'action' => $this->urls->generate(CoreRoutes::LOGOUT),
                'home' => $this->urls->generate(CoreRoutes::HOME),
                'csrf_field' => CsrfTokens::FIELD,
                'csrf_token' => $this->csrf->token(),
            ]), Response::HTTP_OK, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $this->auth->logout($request, ($request->request->all()['everywhere'] ?? '') === '1');

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
    }
}
