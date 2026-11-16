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
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/profile-fields` (2026-11-08): custom profile fields (text or link) members fill in their settings, and
 * post-count ranks shown on profiles and next to posts. Fresh administrator session required; audited.
 */
final readonly class MemberExtrasAdminHandler implements RouteHandler
{
    public function __construct(
        private MemberStanding $standing,
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
        $flash = '_member_extras_notice:' . ($subject->userId ?? 0);
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
                    $action = $text('action');
                    match ($action) {
                        'add_field' => $this->standing->addField($text('label'), $text('kind')),
                        'add_rank' => $this->standing->addRank(
                            $text('title'),
                            ctype_digit($text('min_posts')) ? (int) $text('min_posts') : -1,
                            $text('color'),
                        ),
                        'delete_field' => $this->standing->delete('field', (int) $text('id')),
                        'delete_rank' => $this->standing->delete('rank', (int) $text('id')),
                        default => throw new RegistrationRejected(['community_invalid']),
                    };
                    $this->audit->record($subject->userId, 'member_extras.' . $action);
                    $this->session->set($flash, $this->translator->translate('admin.taxonomy.saved'));

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_MEMBER_EXTRAS));
                } catch (RegistrationRejected) {
                    [$status, $error] = [422, $this->translator->translate('admin.extras.invalid')];
                }
            }
        }

        return new Response($this->views->render('admin/member_extras.twig', [
            'title' => $this->translator->translate('admin.extras.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MEMBER_EXTRAS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_MEMBER_EXTRAS),
            'fields' => $this->standing->fields(),
            'ranks' => array_map(static fn(array $rank): array => $rank + [
                'class' => MemberStanding::COLORS[$rank['color']] ?? 'chip',
            ], $this->standing->ranks()),
            'kinds' => MemberStanding::KINDS,
            'colors' => array_keys(MemberStanding::COLORS),
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
