<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/verify-email` (M02.03): GET with `?token=` shows a confirm button, so mail scanners and link previews that
 * fetch the link do not use the token up; POST (CSRF-checked) uses it and activates the account. Unknown, used,
 * expired and tampered tokens all get the same "link not valid" answer with the resend form.
 * `/verify-email/resend`: GET shows the form, POST asks for a new link and answers the same for every address;
 * only the per-network limit is reported (429 with Retry-After).
 */
final class VerifyEmailHandler implements RouteHandler
{
    private const TOKEN = '/^[A-Za-z0-9_-]{1,64}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly AccountRegistration $registration,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly bool $resend,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        return $this->resend ? $this->resendPage($request) : $this->verifyPage($request);
    }

    private function verifyPage(Request $request): Response
    {
        $token = $request->isMethod('POST') ? $request->request->get('token') : $request->query->get('token');
        $token = is_string($token) && preg_match(self::TOKEN, $token) === 1 ? $token : '';

        if (!$request->isMethod('POST')) {
            return $token === ''
                ? $this->page('auth/verify.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND)
                : $this->page('auth/verify.twig', ['state' => 'confirm', 'token' => $token]);
        }

        return $token !== '' && $this->registration->verify($token) !== null
            ? $this->page('auth/verify.twig', ['state' => 'done'])
            : $this->page('auth/verify.twig', ['state' => 'invalid'], Response::HTTP_NOT_FOUND);
    }

    private function resendPage(Request $request): Response
    {
        $action = $this->urls->generate(CoreRoutes::VERIFY_RESEND);

        if (!$request->isMethod('POST')) {
            return $this->page('auth/resend.twig', [
                'sent' => $request->query->get('sent') === '1',
                'error' => null,
            ]);
        }

        $email = $request->request->get('email');

        try {
            $wait = $this->registration->resend(is_string($email) ? $email : '', $request->getClientIp());
        } catch (MailDeliveryFailed $failure) {
            return $this->page('auth/resend.twig', [
                'sent' => false,
                'error' => $this->translator->translate('diagnostics.' . $failure->diagnostic),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($wait > 0) {
            $response = $this->page('auth/resend.twig', [
                'sent' => false,
                'error' => $this->translator->translate('diagnostics.verify_resend_wait', ['seconds' => $wait]),
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }

        return InstallerPage::redirect($action . '?sent=1');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        $context += [
            'title' => $this->translator->translate($this->resend ? 'auth.resend.title' : 'auth.verify.title'),
            'action' => $this->urls->generate($this->resend ? CoreRoutes::VERIFY_RESEND : CoreRoutes::VERIFY_EMAIL),
            'resend_url' => $this->urls->generate(CoreRoutes::VERIFY_RESEND),
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'token' => '',
            'approval' => $this->registration->mode() === RegistrationRules::APPROVAL,
        ];

        return new Response($this->views->render($template, $context), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
