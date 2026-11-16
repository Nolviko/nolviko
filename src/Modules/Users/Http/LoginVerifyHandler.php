<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second sign-in step (2026-11-15): a code from the member's method or one recovery code. Only reachable with
 * a pending sign-in from the password form; five wrong codes end it, and LoginThrottle counts every code per
 * member, so starting over with the password does not give an unlimited number of guesses.
 */
final class LoginVerifyHandler implements RouteHandler
{
    public const THROTTLE_PREFIX = 'second-factor:';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly SecondFactorLogin $secondFactor,
        private readonly AuthSession $auth,
        private readonly UserRepository $users,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly LoginThrottle $throttle,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $pending = $this->secondFactor->pending();
        if ($this->auth->userId($request) !== null || $pending === null) {
            return InstallerPage::redirect($this->urls->generate(
                $this->auth->userId($request) !== null ? CoreRoutes::HOME : CoreRoutes::LOGIN,
            ));
        }

        if (!$request->isMethod('POST')) {
            return $this->form(null, Response::HTTP_OK);
        }

        $identifier = self::THROTTLE_PREFIX . $pending['user'];
        $wait = $this->throttle->retryAfter($identifier, $request->getClientIp());
        if ($wait > 0) {
            $response = $this->form('login_throttled', Response::HTTP_TOO_MANY_REQUESTS, [
                'minutes' => (int) ceil($wait / 60),
            ]);
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }

        $code = $request->request->all()['code'] ?? '';
        $valid = is_string($code) && $this->secondFactor->factors()->verify($pending['user'], 'sign_in', $code);
        $this->throttle->record($identifier, $request->getClientIp(), $valid, $pending['user']);
        $user = $valid ? $this->users->findById($pending['user']) : null;

        if ($user === null) {
            if ($this->secondFactor->failed() === 0) {
                return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
            }

            return $this->form('second_factor_invalid', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->secondFactor->clear();
        $this->auth->login($user, $pending['remember'], $request);
        $this->secondFactor->signedIn($user->id);

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
    }

    /**
     * @param array<string, int> $parameters
     */
    private function form(?string $diagnostic, int $status, array $parameters = []): Response
    {
        $html = $this->views->render('auth/login_verify.twig', [
            'title' => $this->translator->translate('auth.login_verify.title'),
            'action' => $this->urls->generate(CoreRoutes::LOGIN_VERIFY),
            'cancel_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'error' => $diagnostic === null
                ? null
                : $this->translator->translate('diagnostics.' . $diagnostic, $parameters),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'same-origin',
        ]);
    }
}
