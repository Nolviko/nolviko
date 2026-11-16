<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteGuard;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps a member whose group requires a second sign-in step on the setup page until one is set up (2026-11-15).
 * Costs one session read per request; nothing else (no database, no auth service) is built unless the sign-in
 * marked the session.
 */
final readonly class SecondFactorSetupGuard implements RouteGuard
{
    /**
     * @param Closure(): Session $session
     * @param Closure(): AuthSession $auth
     * @param Closure(): SecondFactorLogin $login
     * @param Closure(): UrlGenerator $urls
     */
    public function __construct(
        private Closure $session,
        private Closure $auth,
        private Closure $login,
        private Closure $urls,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        if (!$this->marked()) {
            return null;
        }

        $route = $parameters['_route'] ?? null;
        if (in_array($route, [CoreRoutes::LOGOUT, CoreRoutes::LOGIN_VERIFY], true)) {
            return null;
        }

        $login = ($this->login)();
        if ($login->setupRequired(($this->auth)()->userId($request)) === null) {
            return null;
        }

        $providers = $login->factors()->providers();
        $setup = [];
        foreach ($providers->ids() as $id) {
            try {
                $setup[] = $providers->get($id)->setupRoute();
            } catch (Throwable) {
                continue;
            }
        }

        if ($setup === [] || in_array($route, $setup, true)) {
            return null;
        }

        return InstallerPage::redirect(($this->urls)()->generate($setup[0]));
    }

    private function marked(): bool
    {
        try {
            return is_int(($this->session)()->get(SecondFactorLogin::SETUP_KEY));
        } catch (Throwable) {
            return false;
        }
    }
}
