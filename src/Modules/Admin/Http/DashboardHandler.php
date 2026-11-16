<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Installer\PostInstallExposure;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminStats;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The first admin page (M04.01): board totals, whether the password was confirmed recently, and the four
 * states the acceptance asks for. Success: the totals (and a confirmation notice after re-authentication).
 * Empty: no forum yet. Error: the database failed, shown as a page with status 503, not a server error. Loading:
 * the page is rendered completely on the server, so there is no loading state without JavaScript.
 */
final class DashboardHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly DbalAdminStats $stats,
        private readonly AdminSession $admin,
        private readonly UrlGenerator $urls,
        private readonly ?PostInstallExposure $exposure = null,
        private readonly ?CsrfTokens $csrf = null,
        private readonly ?RequestSubject $subjects = null,
        private readonly ?Closure $factors = null,
    ) {
    }

    /** Members whose second sign-in step belongs to an extension that is off; they use the password alone. */
    private function unprotected(): int
    {
        try {
            $factors = $this->factors === null ? null : ($this->factors)();

            return $factors instanceof SecondFactors ? $factors->membersWithoutProvider() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $token = is_string($input[CsrfTokens::FIELD] ?? null) ? $input[CsrfTokens::FIELD] : '';

            if (
                !TransportPolicy::allows($request) || !$this->admin->isFresh() || $this->exposure === null
                || $this->csrf === null
                || $this->subjects === null || !$this->subjects->of($request)->allows(AdminGuard::PERMISSION)
                || !$this->csrf->isValid($token) || ($input['action'] ?? null) !== 'check_exposure'
            ) {
                return new Response(
                    $this->translator->translate(
                        TransportPolicy::allows($request)
                            ? 'diagnostics.admin_reauth_required' : 'diagnostics.admin_https_required',
                    ),
                    403,
                    ['Cache-Control' => 'no-store']
                );
            }

            $this->exposure->check($request);
        }

        $stats = $this->stats->read();
        $html = $this->views->render('admin/dashboard.twig', [
            'title' => $this->translator->translate('admin.dashboard.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_DASHBOARD),
            'stats' => $stats,
            'exposure' => $this->exposure?->status(),
            'check_action' => $this->urls->generate(CoreRoutes::ADMIN_DASHBOARD),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf?->token(),
            'empty' => $stats !== null && $stats['forums'] === 0,
            'confirmed' => $request->query->get('confirmed') === '1' && $this->admin->isFresh(),
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'second_factor_off' => $stats === null ? 0 : $this->unprotected(),
        ]);

        return new Response($html, $stats === null ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
