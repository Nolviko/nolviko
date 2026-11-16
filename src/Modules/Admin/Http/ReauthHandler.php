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
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/reauth` (M02.15): the administrator confirms the password to open the FRESH_SECONDS window for
 * sensitive actions. AdminGuard has already checked `admin.access` and CsrfGuard the token. Attempts share the
 * login throttle under their own identifier (`admin-reauth:<user id>`), so guessing is slowed the same way, and
 * every attempt is audited. The password is never sent back.
 */
final class ReauthHandler implements RouteHandler
{
    public const THROTTLE_PREFIX = 'admin-reauth:';

    private bool $codeNeeded = false;

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly AdminSession $admin,
        private readonly DbalAdminAudit $audit,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ?SecondFactors $factors = null,
    ) {
    }

    private function needsCode(int $userId): bool
    {
        return $this->factors !== null && $userId > 0 && $this->factors->active($userId) !== [];
    }

    public function handle(Request $request, array $parameters): Response
    {
        $userId = (int) $this->subjects->userId($request);
        $this->codeNeeded = $this->needsCode($userId);

        $return = $this->returnPath($request);

        if (!$request->isMethod('POST')) {
            return $this->form(null, Response::HTTP_OK, [], $return);
        }

        $identifier = self::THROTTLE_PREFIX . $userId;
        $wait = $this->throttle->retryAfter($identifier, $request->getClientIp());

        if ($wait > 0) {
            $response = $this->form(
                'login_throttled',
                Response::HTTP_TOO_MANY_REQUESTS,
                ['minutes' => (int) ceil($wait / 60)],
                $return,
            );
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }

        $password = $request->request->all()['password'] ?? '';
        $user = $this->users->findById($userId);
        $valid = $user !== null && is_string($password) && $this->hasher->verify($password, $user->passwordHash);
        if ($valid && $this->codeNeeded) {
            // An administrator with a second sign-in step confirms with it too (2026-11-15).
            $code = $request->request->all()['code'] ?? '';
            $valid = is_string($code) && $this->factors?->verify($userId, 'admin_reauth', $code) === true;
        }
        $this->throttle->record($identifier, $request->getClientIp(), $valid, $userId);
        $this->audit->record($userId, $valid ? 'admin.reauth' : 'admin.reauth_failed');

        if (!$valid) {
            return $this->form('admin_reauth_invalid', Response::HTTP_UNPROCESSABLE_ENTITY, [], $return);
        }

        $this->admin->confirm();

        return InstallerPage::redirect(
            $return ?? $this->urls->generate(CoreRoutes::ADMIN_DASHBOARD) . '?confirmed=1',
        );
    }

    /**
     * The admin page to go back to after confirming: the posted `return` field, else the same-host Referer of the
     * form request. Only paths inside the admin area (not this page) are accepted, so it cannot redirect off-site.
     */
    private function returnPath(Request $request): ?string
    {
        $posted = $request->request->all()['return'] ?? null;

        if ($request->isMethod('POST')) {
            $candidate = is_string($posted) ? $posted : '';
        } else {
            $referer = parse_url((string) $request->headers->get('referer', ''));
            $candidate = is_array($referer) && ($referer['host'] ?? '') === $request->getHost()
                ? ($referer['path'] ?? '') . (isset($referer['query']) ? '?' . $referer['query'] : '') : '';
        }

        $admin = $this->urls->generate(CoreRoutes::ADMIN_DASHBOARD);
        $self = $this->urls->generate(CoreRoutes::ADMIN_REAUTH);
        $inside = $candidate === $admin || str_starts_with($candidate, rtrim($admin, '/') . '/')
            || str_starts_with($candidate, $admin . '?');

        return $inside && strlen($candidate) <= 2000 && preg_match('~//|\\\\|[^\x21-\x7e]~', $candidate) !== 1
            && strtok($candidate, '?') !== $self ? $candidate : null;
    }

    /**
     * @param array<string, int> $parameters
     */
    private function form(?string $diagnostic, int $status, array $parameters = [], ?string $return = null): Response
    {
        $remaining = $this->admin->remainingSeconds();

        $html = $this->views->render('admin/reauth.twig', [
            'title' => $this->translator->translate('admin.reauth.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_REAUTH),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'minutes' => intdiv(AdminSession::FRESH_SECONDS, 60),
            'remaining' => $remaining > 0 ? (int) ceil($remaining / 60) : 0,
            'return' => $return,
            'needs_code' => $this->codeNeeded,
            'error' => $diagnostic === null
                ? null
                : $this->translator->translate('diagnostics.' . $diagnostic, $parameters),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
