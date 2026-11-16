<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

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
use Nolviko\Modules\Users\Infrastructure\PasswordRecovery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/password/forgot` (GET form, POST request; the same answer for every address, 429 only for the network
 * limit) and `/password/reset?token=` (GET shows the new-password form for a valid link, POST sets it). Every
 * page is `Cache-Control: no-store` and `Referrer-Policy: no-referrer`, so the token in the address stays in
 * neither caches nor referrers (M02.05). Unknown, used, expired and tampered links get the same 404 answer.
 */
final class PasswordResetHandler implements RouteHandler
{
    private const TOKEN = '/^[A-Za-z0-9_-]{1,64}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly AuthSession $auth,
        private readonly PasswordRecovery $recovery,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly bool $reset,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        return $this->reset ? $this->resetPage($request) : $this->forgotPage($request);
    }

    private function forgotPage(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->page('auth/password_forgot.twig', [
                'sent' => $request->query->get('sent') === '1',
                'error' => null,
            ]);
        }

        $email = $request->request->get('email');

        try {
            $wait = $this->recovery->request(is_string($email) ? $email : '', $request->getClientIp());
        } catch (MailDeliveryFailed) {
            // Do not disclose whether an account exists when its mail cannot be delivered.
            $wait = 0;
        }

        if ($wait > 0) {
            $response = $this->page('auth/password_forgot.twig', [
                'sent' => false,
                'error' => $this->translator->translate('diagnostics.verify_resend_wait', ['seconds' => $wait]),
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::PASSWORD_FORGOT) . '?sent=1');
    }

    private function resetPage(Request $request): Response
    {
        $token = $request->isMethod('POST') ? $request->request->get('token') : $request->query->get('token');
        $token = is_string($token) && preg_match(self::TOKEN, $token) === 1 ? $token : '';

        if ($token === '' || !$this->recovery->isValid($token)) {
            return $this->page('auth/password_reset.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND);
        }

        if (!$request->isMethod('POST')) {
            return $this->page('auth/password_reset.twig', ['state' => 'form', 'token' => $token]);
        }

        $field = static fn(string $name): string => is_string($request->request->get($name))
            ? (string) $request->request->get($name)
            : '';

        try {
            $done = $this->recovery->reset($token, $field('password'), $field('password_confirmation'));
        } catch (RegistrationRejected $rejected) {
            return $this->page('auth/password_reset.twig', [
                'state' => 'form',
                'token' => $token,
                'errors' => array_map(
                    fn(string $id): string => $this->translator->translate('diagnostics.' . $id),
                    $rejected->diagnostics,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$done) {
            return $this->page('auth/password_reset.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND);
        }

        // Every session of the account has ended; so does this one, if it belonged to the account.
        $this->auth->logout($request);

        return $this->page('auth/password_reset.twig', ['state' => 'done']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        $context += [
            'title' => $this->translator->translate($this->reset ? 'auth.reset.title' : 'auth.forgot.title'),
            'action' => $this->urls->generate($this->reset ? CoreRoutes::PASSWORD_RESET : CoreRoutes::PASSWORD_FORGOT),
            'forgot_url' => $this->urls->generate(CoreRoutes::PASSWORD_FORGOT),
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'token' => '',
            'errors' => [],
            'password_min' => PasswordPolicy::MIN_LENGTH,
        ];

        return new Response($this->views->render($template, $context), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
