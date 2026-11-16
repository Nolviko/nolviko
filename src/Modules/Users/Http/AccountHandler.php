<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Infrastructure\AccountSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The signed-in member's own account (M02.16): GET `/account` shows the address and the two forms; POST
 * `/account/password` and `/account/email` (CSRF-checked by the kernel guard) change them for the session's own
 * user only, never one named in the request. `/account/email/confirm?token=` shows a confirm button (GET) and
 * applies the new address (POST); it works without being signed in, since the link proves the mailbox.
 */
final class AccountHandler implements RouteHandler
{
    public const PAGE = 'page';

    public const PASSWORD = 'password';

    public const EMAIL = 'email';

    public const CONFIRM = 'confirm';

    private const TOKEN = '/^[A-Za-z0-9_-]{1,64}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly AuthSession $auth,
        private readonly UserRepository $users,
        private readonly AccountSettings $settings,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly string $mode,
        private readonly ?Closure $factors = null,
        private readonly ?Closure $external = null,
    ) {
    }

    /**
     * Second sign-in methods the extensions offer, with the member's state (2026-11-15).
     *
     * @return list<array{label: string, url: string, active: bool}>
     */
    private function secondFactors(int $userId): array
    {
        $factors = $this->factors === null ? null : ($this->factors)();
        if (!$factors instanceof SecondFactors) {
            return [];
        }

        $active = $factors->active($userId);
        $methods = [];
        foreach ($factors->providerIds() as $id) {
            try {
                $provider = $factors->providers()->get($id);
                $methods[] = [
                    'label' => $this->translator->translate($provider->label()),
                    'url' => $this->urls->generate($provider->setupRoute()),
                    'active' => in_array($id, $active, true),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $methods;
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->mode === self::CONFIRM) {
            return $this->confirm($request);
        }

        $userId = $this->auth->userId($request);

        if ($userId === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        if ($this->mode === self::PAGE || !$request->isMethod('POST')) {
            return $this->page($userId, [
                'notice' => match ($request->query->get('done')) {
                    'password' => $this->translator->translate('auth.account.password_changed'),
                    'email' => $this->translator->translate('auth.account.email_requested'),
                    default => null,
                },
            ]);
        }

        $field = static fn(string $name): string => is_string($request->request->get($name))
            ? (string) $request->request->get($name)
            : '';

        try {
            if ($this->mode === self::PASSWORD) {
                $user = $this->settings->changePassword(
                    $userId,
                    $field('current_password'),
                    $field('password'),
                    $field('password_confirmation'),
                );
                // Every other session has ended; this one starts again under the new epoch.
                $this->auth->login($user, false, $request);
            } else {
                $this->settings->requestEmailChange($userId, $field('current_password'), $field('email'));
            }
        } catch (RegistrationRejected $rejected) {
            return $this->page($userId, [
                'errors' => $this->messages($rejected->diagnostics),
                'form' => $this->mode,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RegistrationThrottled $throttled) {
            $response = $this->page($userId, [
                'errors' => [
                    $this->translator->translate('diagnostics.account_wait', ['seconds' => $throttled->seconds]),
                ],
                'form' => $this->mode,
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $throttled->seconds);

            return $response;
        } catch (MailDeliveryFailed $failure) {
            return $this->page($userId, [
                'errors' => $this->messages([$failure->diagnostic]),
                'form' => $this->mode,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::ACCOUNT) . '?done=' . $this->mode);
    }

    private function confirm(Request $request): Response
    {
        $token = $request->isMethod('POST') ? $request->request->get('token') : $request->query->get('token');
        $token = is_string($token) && preg_match(self::TOKEN, $token) === 1 ? $token : '';

        if ($token === '' || !$this->settings->isValidChange($token)) {
            return $this->render('auth/email_confirm.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND);
        }

        if (!$request->isMethod('POST')) {
            return $this->render('auth/email_confirm.twig', ['state' => 'confirm', 'token' => $token]);
        }

        return $this->settings->confirmEmailChange($token) === null
            ? $this->render('auth/email_confirm.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND)
            : $this->render('auth/email_confirm.twig', ['state' => 'done']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(int $userId, array $context, int $status = Response::HTTP_OK): Response
    {
        $user = $this->users->findById($userId);

        return $this->render('auth/account.twig', $context + [
            'email' => $user?->email ?? '',
            'username' => $user?->username ?? '',
            'conversations_url' => $this->urls->generate(CoreRoutes::CONVERSATIONS),
            'privacy_url' => $this->urls->generate(CoreRoutes::ACCOUNT_PRIVACY),
            'password_action' => $this->urls->generate(CoreRoutes::ACCOUNT_PASSWORD),
            'email_action' => $this->urls->generate(CoreRoutes::ACCOUNT_EMAIL),
            'second_factors' => $this->secondFactors($userId),
            // Outside accounts (2026-11-15): {accounts: list, notice: ?string, csrf_token, unlink_action}.
            'external' => $this->external === null ? null : ($this->external)($userId),
            'errors' => [],
            'form' => null,
            'notice' => null,
        ], $status);
    }

    /**
     * @param list<string> $diagnostics
     *
     * @return list<string>
     */
    private function messages(array $diagnostics): array
    {
        return array_map(fn(string $id): string => $this->translator->translate('diagnostics.' . $id), $diagnostics);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        $context += [
            'title' => $this->translator->translate(
                $this->mode === self::CONFIRM ? 'auth.email_confirm.title' : 'auth.account.title',
            ),
            'action' => $this->urls->generate(CoreRoutes::ACCOUNT_EMAIL_CONFIRM),
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'password_min' => PasswordPolicy::MIN_LENGTH,
            'token' => '',
        ];

        return new Response($this->views->render($template, $context), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
