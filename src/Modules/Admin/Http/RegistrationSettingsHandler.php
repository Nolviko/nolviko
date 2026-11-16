<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/admin/registration` (M02.03a, `admin.access` via AdminGuard): how registration works on this site, read
 * from the configuration — mode, honeypot, minimum fill time, challenge, network limit — and whether any data
 * goes to an outside service (none of the built-in options does). Settings are changed in `config/local.php`
 * until the settings screens arrive (M07).
 */
final class RegistrationSettingsHandler implements RouteHandler
{
    private const MODES = [
        RegistrationRules::OPEN => 'admin.registration.mode_open',
        RegistrationRules::VERIFY => 'admin.registration.mode_verify',
        RegistrationRules::APPROVAL => 'admin.registration.mode_approval',
        RegistrationRules::CLOSED => 'admin.registration.mode_closed',
    ];

    private const CHALLENGES = [
        RegistrationRules::CHALLENGE_NONE => 'admin.registration.challenge_none',
        RegistrationRules::CHALLENGE_QUESTION => 'admin.registration.challenge_question',
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RegistrationRules $rules,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $html = $this->views->render('admin/registration.twig', [
            'title' => $this->translator->translate('admin.registration.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_REGISTRATION),
            'mode' => $this->translator->translate(self::MODES[$this->rules->mode] ?? 'admin.registration.mode_verify'),
            'min_seconds' => $this->rules->minSeconds,
            'network_seconds' => $this->rules->networkSeconds,
            'challenge' => $this->translator->translate(
                self::CHALLENGES[$this->rules->challenge] ?? 'admin.registration.challenge_none',
            ),
            'queue_url' => $this->urls->generate(CoreRoutes::MODERATION_QUEUE),
            'approval' => $this->rules->mode === RegistrationRules::APPROVAL,
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
