<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\AccessBans;
use Nolviko\Modules\Users\Domain\AccountBans;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginResult;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET shows the login form; POST (CSRF-checked) logs in by username or email. Failures re-show the form with
 * one generic message; the password is never sent back. LoginThrottle is asked first: a throttled attempt is
 * answered 429 with Retry-After and the password is not checked at all. A banned account is refused after the
 * password check with the ban's end (M04.09), so only someone with the password learns of the ban; IP and
 * email bans are checked at the same point (M04.09a).
 */
final class LoginHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly Authenticator $authenticator,
        private readonly AuthSession $auth,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly LoginThrottle $throttle,
        private readonly ?AccountBans $bans = null,
        private readonly ?AccessBans $access = null,
        private readonly ?SecondFactorLogin $secondFactor = null,
        private readonly ?Closure $external = null,
    ) {
    }

    /** Where to go after signing in (beta.10), for example back to an interrupted reply. */
    private ?string $return = null;

    public function handle(Request $request, array $parameters): Response
    {
        $this->return = ReturnPath::of($request);
        if ($this->auth->userId($request) !== null) {
            return InstallerPage::redirect($this->return ?? $this->urls->generate(CoreRoutes::HOME));
        }

        if (!$request->isMethod('POST')) {
            return $this->form('', null, Response::HTTP_OK);
        }

        $input = $request->request->all();
        $identifier = is_string($input['identifier'] ?? null) ? $input['identifier'] : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $wait = $this->throttle->retryAfter($identifier, $request->getClientIp());

        if ($wait > 0) {
            $response = $this->form($identifier, 'login_throttled', Response::HTTP_TOO_MANY_REQUESTS, [
                'minutes' => (int) ceil($wait / 60),
            ]);
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }

        $result = $this->authenticator->attempt($identifier, $password);
        $passwordMatched = $result->diagnostic !== LoginResult::INVALID;
        $this->throttle->record($identifier, $request->getClientIp(), $passwordMatched, $result->user?->id);

        if (!$result->succeeded() || $result->user === null) {
            return $this->form($identifier, $result->diagnostic, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($this->access?->blocksUser($result->user->id, $request->getClientIp(), $now) === true) {
            return $this->form($identifier, 'login_access_banned', Response::HTTP_FORBIDDEN);
        }

        $until = $this->bans?->activeBanUntil($result->user->id, $now);

        if ($this->bans !== null && $until !== false) {
            return $until === null
                ? $this->form($identifier, 'login_account_banned', Response::HTTP_FORBIDDEN)
                : $this->form($identifier, 'login_account_banned_until', Response::HTTP_FORBIDDEN, [
                    'until' => $until->format('Y-m-d H:i \U\T\C'),
                ]);
        }

        $remember = ($input['remember'] ?? '') === '1';
        if ($this->secondFactor?->needsCode($result->user->id) === true) {
            // Not signed in yet: the second step (2026-11-15) finishes the sign-in.
            $this->secondFactor->begin($result->user->id, $remember);

            return InstallerPage::redirect(
                ReturnPath::append($this->urls->generate(CoreRoutes::LOGIN_VERIFY), $this->return),
            );
        }

        $this->auth->login($result->user, $remember, $request);
        $this->secondFactor?->signedIn($result->user->id);

        return InstallerPage::redirect($this->return ?? $this->urls->generate(CoreRoutes::HOME));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function form(string $identifier, ?string $diagnostic, int $status, array $parameters = []): Response
    {
        $html = $this->views->render('auth/login.twig', [
            'language' => str_replace('_', '-', $this->translator->locale()),
            'title' => $this->translator->translate('auth.login.title'),
            'action' => ReturnPath::append($this->urls->generate(CoreRoutes::LOGIN), $this->return),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'identifier' => $identifier,
            'register_url' => $this->urls->generate(CoreRoutes::REGISTER),
            'forgot_url' => $this->urls->generate(CoreRoutes::PASSWORD_FORGOT),
            'resend_url' => $this->urls->generate(CoreRoutes::VERIFY_RESEND),
            // Sign in with an outside account (2026-11-15): [{label, url}] of the available providers.
            'external' => $this->external === null ? [] : ($this->external)(),
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
