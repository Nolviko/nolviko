<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Extensions\AuthChallenge;
use Nolviko\Core\Extensions\ExternalIdentity;
use Nolviko\Core\Extensions\IdentityProviders;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Users\Domain\AccessBans;
use Nolviko\Modules\Users\Domain\AccountBans;
use Nolviko\Modules\Users\Domain\IdentityRules;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Nolviko\Modules\Users\Infrastructure\ExternalIdentities;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Signing in with an outside account (2026-11-15, Google first). One handler, four routes:
 * - `auth.external` GET starts a sign-in, POST (CSRF-checked, signed in) starts linking from the account page;
 * - `auth.external_callback` checks the session-bound state (single use, ten minutes), lets the provider exchange
 *   the code with the PKCE verifier and nonce, then signs in a linked member (bans, status and the second step
 *   apply), links, or starts a sign-up;
 * - `auth.external_register` lets a new member choose a username; only a provider-verified address that has no
 *   account may sign up, so an outside account never takes over an existing one;
 * - `account.external` POST unlinks with the current password.
 */
final class ExternalAuthHandler implements RouteHandler
{
    public const START = 'start';

    public const CALLBACK = 'callback';

    public const REGISTER = 'register';

    public const UNLINK = 'unlink';

    /** Link to an existing account with its password, during sign-in (owner request 2026-11-15). */
    public const LINK = 'link';

    public const LINK_THROTTLE_PREFIX = 'external-link:';

    public const THROTTLE_PREFIX = 'external-unlink:';

    private const PENDING_KEY = '_external_auth';

    private const SIGNUP_KEY = '_external_signup';

    private const LINK_KEY = '_external_link';

    /**
     * @param Closure(): AccountRegistration $registration
     * @param Closure(): SecondFactorLogin $secondFactor
     */
    public function __construct(
        private readonly string $mode,
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly IdentityProviders $providers,
        private readonly ExternalIdentities $identities,
        private readonly AuthSession $auth,
        private readonly Session $session,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly SiteUrl $site,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly ErrorResponder $errors,
        private readonly Closure $registration,
        private readonly Closure $secondFactor,
        private readonly ?AccountBans $bans = null,
        private readonly ?AccessBans $access = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        return match ($this->mode) {
            self::START => $this->start($request, (string) ($parameters['provider'] ?? '')),
            self::CALLBACK => $this->callback($request, (string) ($parameters['provider'] ?? '')),
            self::REGISTER => $this->register($request),
            self::LINK => $this->linkExisting($request),
            default => $this->unlink($request),
        };
    }

    private function start(Request $request, string $slug): Response
    {
        $provider = $this->providers->get($slug);
        $userId = $this->auth->userId($request);
        $link = $request->isMethod('POST');
        if ($provider === null) {
            return $this->errors->notFound();
        }
        if ($link ? $userId === null : $userId !== null) {
            return InstallerPage::redirect($this->urls->generate($link ? CoreRoutes::LOGIN : CoreRoutes::HOME));
        }
        $redirect = $this->site->absolute($this->urls->generate(CoreRoutes::AUTH_EXTERNAL_CALLBACK, [
            'provider' => $slug,
        ]));
        if ($redirect === null) {
            return $this->message('external_unavailable', 503);
        }

        $verifier = self::base64Url(random_bytes(48));
        $pending = [
            'provider' => $slug, 'state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(32)),
            'verifier' => $verifier, 'purpose' => $link ? 'account_link' : 'sign_in', 'user' => $userId ?? 0,
            'until' => time() + 600,
        ];
        $this->session->set(self::PENDING_KEY, $pending);

        return InstallerPage::redirect($provider->authorizationUrl(
            $this->challenge($slug, $pending),
            $redirect,
            $pending['state'],
            self::base64Url(hash('sha256', $verifier, true)),
        ));
    }

    private function callback(Request $request, string $slug): Response
    {
        $pending = $this->session->get(self::PENDING_KEY);
        $this->session->remove(self::PENDING_KEY);
        $provider = $this->providers->get($slug);
        $state = $request->query->get('state');
        $code = $request->query->get('code');
        if (
            $provider === null || !is_array($pending) || ($pending['provider'] ?? null) !== $slug
            || !is_string($pending['state'] ?? null) || !is_string($state) || !hash_equals($pending['state'], $state)
            || !is_int($pending['until'] ?? null) || $pending['until'] < time()
        ) {
            return $this->message('external_expired', 400);
        }
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            return $this->message('external_cancelled', 400);
        }

        try {
            $identity = $provider->identity(
                $this->challenge($slug, $pending),
                (string) $this->site->absolute($this->urls->generate(CoreRoutes::AUTH_EXTERNAL_CALLBACK, [
                    'provider' => $slug,
                ])),
                $code,
                (string) $pending['verifier'],
            );
        } catch (Throwable) {
            return $this->message('external_failed', 502);
        }
        if ($identity->provider !== $this->providers->owner($slug)) {
            return $this->message('external_failed', 502);
        }

        if ($pending['purpose'] === 'account_link') {
            if ($this->auth->userId($request) !== $pending['user']) {
                return $this->message('external_expired', 400);
            }
            try {
                $this->identities->link((int) $pending['user'], $identity);
            } catch (RegistrationRejected $rejected) {
                return $this->message($rejected->diagnostics[0] ?? 'external_failed', 409);
            }

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::ACCOUNT) . '?external=linked');
        }

        $userId = $this->identities->userOf($identity);
        if ($userId !== null) {
            $user = $this->users->findById($userId);

            return $user === null ? $this->message('external_failed', 502) : $this->signIn($user, $request);
        }
        if ($identity->verifiedEmail === null) {
            return $this->message('external_unverified', 403);
        }
        $existing = $this->users->findByEmail($identity->verifiedEmail);
        if ($existing !== null) {
            // The address has an account: link only after that account's password (never a takeover).
            $this->session->set(self::LINK_KEY, [
                'provider' => $identity->provider, 'subject' => $identity->subject,
                'email' => $identity->verifiedEmail, 'user' => $existing->id, 'until' => time() + 900,
            ]);

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::AUTH_EXTERNAL_LINK));
        }
        if (($this->registration)()->mode() === 'closed') {
            return $this->message('registration_closed', 403);
        }

        $this->session->set(self::SIGNUP_KEY, [
            'provider' => $identity->provider, 'subject' => $identity->subject, 'email' => $identity->verifiedEmail,
            'name' => $identity->name, 'until' => time() + 900,
        ]);

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::AUTH_EXTERNAL_REGISTER));
    }

    /**
     * The Google address belongs to an account: the member proves it with that account's password, then the outside
     * account is linked and the sign-in finishes (with the second step when the member has one). Wrong passwords
     * count in LoginThrottle per account; five wrong ones end the pending link.
     */
    private function linkExisting(Request $request): Response
    {
        $pending = $this->session->get(self::LINK_KEY);
        $user = is_array($pending) && is_int($pending['user'] ?? null) && is_int($pending['until'] ?? null)
            && $pending['until'] >= time() && $this->auth->userId($request) === null
            ? $this->users->findById($pending['user']) : null;
        if ($user === null) {
            $this->session->remove(self::LINK_KEY);

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $identifier = self::LINK_THROTTLE_PREFIX . $user->id;
            $wait = $this->throttle->retryAfter($identifier, $request->getClientIp());
            $password = $request->request->all()['password'] ?? '';
            if ($wait > 0) {
                [$error, $status] = [$this->translator->translate('diagnostics.login_throttled', [
                    'minutes' => (int) ceil($wait / 60),
                ]), 429];
            } else {
                $valid = is_string($password) && $this->hasher->verify($password, $user->passwordHash);
                $this->throttle->record($identifier, $request->getClientIp(), $valid, $user->id);
                if ($valid) {
                    $this->session->remove(self::LINK_KEY);
                    try {
                        $this->identities->link($user->id, new ExternalIdentity(
                            (string) $pending['provider'],
                            (string) $pending['subject'],
                            (string) $pending['email'],
                        ));
                    } catch (RegistrationRejected $rejected) {
                        return $this->message($rejected->diagnostics[0] ?? 'external_failed', 409);
                    }

                    return $this->signIn($user, $request);
                }
                $tries = (int) ($pending['tries'] ?? 0) + 1;
                if ($tries >= 5) {
                    $this->session->remove(self::LINK_KEY);

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
                }
                $this->session->set(self::LINK_KEY, ['tries' => $tries] + $pending);
                [$error, $status] = [$this->translator->translate('diagnostics.external_link_password'), 422];
            }
        }

        return $this->page('auth/external.twig', [
            'title' => $this->translator->translate('auth.external.link_title'),
            'link' => [
                'action' => $this->urls->generate(CoreRoutes::AUTH_EXTERNAL_LINK),
                'email' => (string) $pending['email'],
                'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            ],
            'register' => null, 'message' => null, 'errors' => $error === null ? [] : [$error],
        ], $status);
    }

    private function register(Request $request): Response
    {
        $signup = $this->session->get(self::SIGNUP_KEY);
        if (
            !is_array($signup) || !is_int($signup['until'] ?? null) || $signup['until'] < time()
            || !is_string($signup['email'] ?? null) || $this->auth->userId($request) !== null
        ) {
            $this->session->remove(self::SIGNUP_KEY);

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $username = self::suggestion((string) ($signup['name'] ?? ''), $signup['email']);
        $errors = [];
        if ($request->isMethod('POST')) {
            $username = (string) ($request->request->all()['username'] ?? '');
            try {
                $user = ($this->registration)()->registerExternal($username, $signup['email'], $request->getClientIp());
                $this->identities->link($user->id, new ExternalIdentity(
                    (string) $signup['provider'],
                    (string) $signup['subject'],
                    $signup['email'],
                ));
                $this->session->remove(self::SIGNUP_KEY);
                if ($user->status !== UserStatus::Active) {
                    return $this->message('external_pending_approval', 200);
                }
                $this->auth->login($user, false, $request, confirmsAdmin: false);
                ($this->secondFactor)()->signedIn($user->id);

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
            } catch (RegistrationRejected $rejected) {
                $errors = array_map($this->diagnostic(...), $rejected->diagnostics);
            } catch (RegistrationThrottled $throttled) {
                $errors = [$this->translator->translate('diagnostics.register_wait', [
                    'seconds' => $throttled->seconds,
                ])];
            }
        }

        return $this->page('auth/external.twig', [
            'title' => $this->translator->translate('auth.external.register_title'),
            'register' => [
                'action' => $this->urls->generate(CoreRoutes::AUTH_EXTERNAL_REGISTER),
                'email' => $signup['email'], 'username' => $username,
                'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            ],
            'errors' => $errors, 'message' => null, 'link' => null,
        ], $errors === [] ? 200 : 422);
    }

    private function unlink(Request $request): Response
    {
        $userId = $this->auth->userId($request);
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $input = $request->request->all();
        $slug = is_string($input['provider'] ?? null) ? $input['provider'] : '';
        $password = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        $identifier = self::THROTTLE_PREFIX . $user->id;
        if ($this->throttle->retryAfter($identifier, $request->getClientIp()) > 0) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::ACCOUNT) . '?external=throttled');
        }
        $valid = $this->hasher->verify($password, $user->passwordHash);
        $this->throttle->record($identifier, $request->getClientIp(), $valid, $user->id);
        if (!$valid || $this->providers->get($slug) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::ACCOUNT) . '?external=password');
        }
        $this->identities->unlink($user->id, $this->providers->owner($slug));

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::ACCOUNT) . '?external=unlinked');
    }

    /** A linked member: the same refusals as the password form, then the second step when the member has one. */
    private function signIn(User $user, Request $request): Response
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($user->status !== UserStatus::Active) {
            return $this->message('external_inactive', 403);
        }
        if (
            $this->access?->blocksUser($user->id, $request->getClientIp(), $now) === true
            || ($this->bans !== null && $this->bans->activeBanUntil($user->id, $now) !== false)
        ) {
            return $this->message('login_account_banned', 403);
        }

        $secondFactor = ($this->secondFactor)();
        if ($secondFactor->needsCode($user->id)) {
            $secondFactor->begin($user->id, false);

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN_VERIFY));
        }
        $this->auth->login($user, false, $request, confirmsAdmin: false);
        $secondFactor->signedIn($user->id);

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
    }

    /** @param array<string, mixed> $pending */
    private function challenge(string $slug, array $pending): AuthChallenge
    {
        return new AuthChallenge(
            $this->providers->owner($slug),
            (int) $pending['user'],
            (string) $pending['purpose'],
            (string) $pending['nonce'],
            (int) $pending['until'],
        );
    }

    private function message(string $diagnostic, int $status): Response
    {
        return $this->page('auth/external.twig', [
            'title' => $this->translator->translate('auth.external.title'),
            'message' => $this->diagnostic($diagnostic),
            'register' => null, 'errors' => [], 'link' => null,
        ], $status);
    }

    private function diagnostic(string $diagnostic): string
    {
        [$key, $field] = array_pad(explode(':', $diagnostic, 2), 2, null);
        $parameters = $field === null ? [] : ['field' => $field];

        return $this->translator->has('diagnostics.' . $key)
            ? $this->translator->translate('diagnostics.' . $key, $parameters)
            : $this->translator->translate('diagnostics.external_failed');
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, int $status): Response
    {
        return new Response($this->views->render($template, $context + [
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'forgot_url' => $this->urls->generate(CoreRoutes::PASSWORD_FORGOT),
        ]), $status, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    /**
     * Sign-in buttons for the login and registration pages: the available providers, or none (2026-11-15).
     *
     * @return list<array{label: string, url: string}>
     */
    public static function buttons(
        ?IdentityProviders $providers,
        UrlGenerator $urls,
        MessageTranslator $translator,
    ): array {
        $buttons = [];
        try {
            foreach ($providers?->available() ?? [] as $slug => $provider) {
                $buttons[] = [
                    'label' => $translator->translate($provider->label()),
                    'url' => $urls->generate(CoreRoutes::AUTH_EXTERNAL, ['provider' => $slug]),
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $buttons;
    }

    /**
     * The account page's list: each available provider, whether this member linked it, and the forms' targets.
     *
     * @return list<array{slug: string, label: string, name: string, linked: bool, email: string, link_url: string}>
     */
    public static function accounts(
        ?IdentityProviders $providers,
        ?ExternalIdentities $identities,
        int $userId,
        UrlGenerator $urls,
        MessageTranslator $translator,
    ): array {
        if ($providers === null || $identities === null) {
            return [];
        }

        $rows = [];
        try {
            $linked = $identities->forUser($userId);
            foreach ($providers->available() as $slug => $provider) {
                $owner = $providers->owner($slug);
                $rows[] = [
                    'slug' => $slug, 'label' => $translator->translate($provider->label()), 'name' => ucfirst($slug),
                    'linked' => isset($linked[$owner]), 'email' => $linked[$owner] ?? '',
                    'link_url' => $urls->generate(CoreRoutes::AUTH_EXTERNAL, ['provider' => $slug]),
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /** A username from the provider's name, else the address before the @, if the rules accept it. */
    private static function suggestion(string $name, string $email): string
    {
        foreach ([$name, strstr($email, '@', true) ?: ''] as $candidate) {
            $candidate = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $candidate)), 0, 32);
            if (IdentityRules::username($candidate) !== null) {
                return $candidate;
            }
        }

        return '';
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
