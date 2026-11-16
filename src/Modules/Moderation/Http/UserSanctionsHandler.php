<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Moderation\Domain\ManageSanctions;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/moderation/users/{user}` shows a user's ban state and active warning points with the ban, lift and warn
 * forms; POST (CSRF-checked by the kernel guard) applies one of them and redirects back (M04.09). Without
 * `moderation.user.ban` the page is 403; an unknown user is 404.
 */
final class UserSanctionsHandler implements RouteHandler
{
    private const STATUS = [
        'moderation_denied' => Response::HTTP_FORBIDDEN,
        'sanction_admin_denied' => Response::HTTP_FORBIDDEN,
        'user_not_banned' => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ManageSanctions $sanctions,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $subject = $this->subjects->of($request);
        $userId = (int) ($parameters['user'] ?? 0);
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            try {
                $this->sanctions->apply($subject, $userId, $request->request->all());

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::MODERATION_USER, ['user' => $userId]));
            } catch (ThreadRuleViolation $violation) {
                if ($violation->diagnostic === 'user_not_found') {
                    return $this->errors->notFound();
                }

                $error = $this->translator->translate('diagnostics.' . $violation->diagnostic, $violation->parameters);
                $status = self::STATUS[$violation->diagnostic] ?? Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        try {
            $standing = $this->sanctions->standing($subject, $userId);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic === 'user_not_found'
                ? $this->errors->notFound()
                : $this->errors->denied();
        }

        $until = $standing['banned_until'];
        $html = $this->views->render('moderation/user.twig', [
            'title' => $this->translator->translate('moderation.user.title', ['name' => $standing['username']]),
            'ban_state' => match (true) {
                !$standing['banned'] => $this->translator->translate('moderation.user.not_banned'),
                $until === null => $this->translator->translate('moderation.user.banned_forever'),
                default => $this->translator->translate('moderation.user.banned_until', [
                    'until' => $until->format('Y-m-d H:i \U\T\C'),
                ]),
            },
            'banned' => $standing['banned'],
            'points' => $this->translator->translate('moderation.user.points', ['points' => $standing['points']]),
            'error' => $error,
            'action' => $this->urls->generate(CoreRoutes::MODERATION_USER, ['user' => $userId]),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'choices' => [
                'ban' => ManageSanctions::BAN,
                'lift' => ManageSanctions::LIFT,
                'warn' => ManageSanctions::WARN,
            ],
            'max_reason' => ReportPost::MAX_REASON_LENGTH,
            'max_days' => ManageSanctions::MAX_DAYS,
            'max_points' => ManageSanctions::MAX_POINTS,
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
