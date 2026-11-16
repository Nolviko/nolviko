<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Admin\Infrastructure\SystemDiagnostics;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protected diagnostics and explicit POST-only, fresh-admin support export. */
final readonly class DiagnosticsHandler implements RouteHandler
{
    public function __construct(
        private SystemDiagnostics $diagnostics,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private DbalAdminAudit $audit,
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
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            if (
                !TransportPolicy::allows($request) || !$this->admin->isFresh()
                || !is_string($input[CsrfTokens::FIELD] ?? null) || !$this->csrf->isValid($input[CsrfTokens::FIELD])
                || ($input['action'] ?? null) !== 'support'
            ) {
                $error = $this->translator->translate('operations.denied');
                $status = 403;
            } else {
                $this->audit->record($subject->userId, 'admin.support_bundle_requested');
                $json = $this->diagnostics->bundle();
                $this->audit->record($subject->userId, 'admin.support_bundle_completed');
                return new Response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="Nolviko-support.json"',
                    'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
                    'X-Content-Type-Options' => 'nosniff']);
            }
        }
        return new Response($this->views->render('admin/diagnostics.twig', [
            'title' => $this->translator->translate('operations.diagnostics'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_DIAGNOSTICS),
            'report' => $this->diagnostics->snapshot(), 'error' => $error,
            'action' => $this->urls->generate(CoreRoutes::ADMIN_DIAGNOSTICS),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer']);
    }
}
