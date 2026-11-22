<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Content\Infrastructure\AttachmentInventory;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/attachments` (beta.18 roadmap 15 and 16): the allowed attachment types and size are set under Admin >
 * Site settings; this page shows the inventory (newest first, filter by type, totals) and removes selected files.
 * Removal needs a fresh administrator session and is audited.
 */
final readonly class AttachmentsAdminHandler implements RouteHandler
{
    public function __construct(
        private AttachmentInventory $inventory,
        private PrivateMedia $media,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private DbalAdminAudit $audit,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $action = $this->urls->generate(CoreRoutes::ADMIN_ATTACHMENTS);
        [$status, $error] = [200, null];

        if ($request->isMethod('POST')) {
            $token = $request->request->get(CsrfTokens::FIELD);

            if (
                !$this->csrf->isValid(is_string($token) ? $token : '') || !$this->admin->isFresh()
                || $subject->userId === null || !$subject->allows('admin.access')
            ) {
                [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
            } else {
                $ids = $request->request->all('media');
                $removed = $this->inventory->remove(array_values(array_filter($ids, 'is_string')));
                $this->audit->record($subject->userId, 'attachments.remove', ['count' => $removed]);

                return InstallerPage::redirect($action);
            }
        }

        $type = $request->query->get('type');
        $type = is_string($type) && in_array($type, PrivateMedia::TYPES, true) ? $type : '';
        $before = $request->query->get('before');
        $page = $this->inventory->page(is_string($before) ? $before : null, $type);
        $policy = $this->media->policy();

        return new Response($this->views->render('admin/attachments.twig', [
            'title' => $this->translator->translate('content.attachments.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_ATTACHMENTS),
            'action' => $action,
            'page' => $page,
            'type' => $type,
            'types' => $this->inventory->types(),
            'allowed' => implode(', ', $policy['types']),
            'max_mb' => intdiv($policy['bytes'], 1048576),
            'next_url' => $page['next'] === null ? null : $action . '?' . http_build_query(['before' => $page['next'],
                'type' => $type]),
            'settings_url' => $this->urls->generate(CoreRoutes::ADMIN_SITE),
            'error' => $error,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
