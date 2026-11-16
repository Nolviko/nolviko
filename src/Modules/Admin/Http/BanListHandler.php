<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Moderation\Domain\BanEntry;
use Nolviko\Modules\Moderation\Domain\ManageBans;
use Nolviko\Modules\Moderation\Domain\ManageSanctions;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/admin/bans` lists active IP and email bans with an add form and a lift button each; POST (CSRF-checked,
 * `admin.access` via AdminGuard) adds or lifts one and needs a password confirmation from the last 15 minutes
 * (M04.09a), because a wrong range can lock out many members.
 */
final class BanListHandler implements RouteHandler
{
    /** Ban type => translation key of its label. */
    private const TYPE_LABELS = [
        BanEntry::IP => 'admin.bans.type.ip',
        BanEntry::IP_RANGE => 'admin.bans.type.ip_range',
        BanEntry::EMAIL => 'admin.bans.type.email',
        BanEntry::EMAIL_DOMAIN => 'admin.bans.type.email_domain',
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ManageBans $bans,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $error = null;
        $status = Response::HTTP_OK;
        $input = $request->request->all();

        if ($request->isMethod('POST')) {
            try {
                if (!$this->admin->isFresh()) {
                    throw new ThreadRuleViolation('admin_reauth_required');
                }

                $this->bans->apply($subject, $input, $request->getClientIp());

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_BANS));
            } catch (ThreadRuleViolation $violation) {
                $error = $this->translator->translate('diagnostics.' . $violation->diagnostic, $violation->parameters);
                $status = match ($violation->diagnostic) {
                    'moderation_denied', 'admin_reauth_required' => Response::HTTP_FORBIDDEN,
                    'ban_not_found' => Response::HTTP_NOT_FOUND,
                    default => Response::HTTP_UNPROCESSABLE_ENTITY,
                };
            }
        }

        $rows = [];

        foreach ($this->bans->active($subject) as $ban) {
            [$first, $last] = $ban['entry']->display();
            $rows[] = [
                'id' => (string) $ban['id'],
                'type' => $this->translator->translate(self::TYPE_LABELS[$ban['entry']->type] ?? 'admin.bans.type'),
                'value' => $last === null
                    ? $first
                    : $this->translator->translate('admin.bans.range', ['first' => $first, 'last' => $last]),
                'reason' => $ban['reason'],
                'expires' => $ban['expires_at'] === null
                    ? $this->translator->translate('admin.bans.forever')
                    : $ban['expires_at']->format('Y-m-d H:i \U\T\C'),
            ];
        }

        $types = [];

        foreach (self::TYPE_LABELS as $type => $label) {
            $types[] = ['value' => $type, 'label' => $this->translator->translate($label)];
        }

        $html = $this->views->render('admin/bans.twig', [
            'title' => $this->translator->translate('admin.bans.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_BANS),
            'error' => $error,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'rows' => $rows,
            'types' => $types,
            'value' => $error === null || !is_string($input['value'] ?? null) ? '' : $input['value'],
            'action' => $this->urls->generate(CoreRoutes::ADMIN_BANS),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'choices' => ['add' => ManageBans::ADD, 'lift' => ManageBans::LIFT],
            'max_reason' => ReportPost::MAX_REASON_LENGTH,
            'max_days' => ManageSanctions::MAX_DAYS,
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
