<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Api\Infrastructure\Webhooks;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/webhooks` (2026-11-08): HTTPS endpoints that receive new public threads and replies, plus the address of
 * the read-only JSON API. A new endpoint's signing secret is shown once. Fresh administrator session; audited.
 */
final readonly class WebhooksAdminHandler implements RouteHandler
{
    public function __construct(
        private Webhooks $webhooks,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private DbalAdminAudit $audit,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $flash = '_webhook_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        [$status, $error] = [200, null];

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

            if (
                !$this->csrf->isValid($text(CsrfTokens::FIELD)) || !$this->admin->isFresh()
                || $subject->userId === null || !$subject->allows('admin.access')
            ) {
                [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
            } else {
                try {
                    if ($text('action') === 'add') {
                        $events = is_array($input['events'] ?? null) ? array_filter($input['events'], 'is_string') : [];
                        $secret = $this->webhooks->add($text('url'), array_values($events));
                        $this->session->set($flash, $this->translator->translate('admin.webhooks.added', [
                            'secret' => $secret,
                        ]));
                    } else {
                        $this->webhooks->delete((int) $text('id'));
                        $this->session->set($flash, $this->translator->translate('admin.taxonomy.saved'));
                    }

                    $this->audit->record(
                        $subject->userId,
                        $text('action') === 'add' ? 'webhook.added' : 'webhook.deleted',
                    );

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_WEBHOOKS));
                } catch (RegistrationRejected $rejected) {
                    [$status, $error] = [422, $this->translator->translate(
                        'diagnostics.' . ($rejected->diagnostics[0] ?? 'webhook_url_invalid'),
                    )];
                }
            }
        }

        return new Response($this->views->render('admin/webhooks.twig', [
            'title' => $this->translator->translate('admin.webhooks.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_WEBHOOKS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_WEBHOOKS),
            'hooks' => $this->webhooks->all(),
            'events' => Webhooks::EVENTS,
            'api_url' => $this->urls->generate(CoreRoutes::API_THREADS),
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
