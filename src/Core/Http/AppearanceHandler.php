<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalThemePreference;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A non-sensitive, same-site preference persists in this browser; only three fixed appearance values exist. */
final readonly class AppearanceHandler implements RouteHandler
{
    public function __construct(
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private CsrfTokens $csrf,
        private UrlGenerator $urls,
        private ThemeRequest $theme,
        private ?RequestSubject $subjects = null,
        private ?DbalThemePreference $preferences = null
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $token = $input[CsrfTokens::FIELD] ?? null;
            $mode = $input['appearance'] ?? null;
            if (!is_string($token) || !$this->csrf->isValid($token)) {
                $status = 403;
                $error = $this->translator->translate('core.theme.invalid');
            } elseif (!in_array($mode, ['system', 'light', 'dark'], true)) {
                $status = 422;
                $error = $this->translator->translate('core.theme.invalid');
            } else {
                $user = $this->subjects?->of($request)->userId;
                if ($user !== null) {
                    $this->preferences?->set($user, $mode);
                }
                $response = InstallerPage::redirect($this->urls->generate(CoreRoutes::APPEARANCE));
                $base = rtrim($this->urls->generate(CoreRoutes::HOME), '/') . '/';
                $response->headers->setCookie(Cookie::create(
                    'nolviko_appearance',
                    $mode,
                    time() + 31536000,
                    $base,
                    secure: $request->isSecure(),
                    httpOnly: true,
                    sameSite: 'lax'
                ));
                $response->headers->set('Cache-Control', 'no-store, private');
                return $response;
            }
        }
        return new Response($this->views->render('appearance.twig', [
            'title' => $this->translator->translate('core.theme.title'),
            'action' => $this->urls->generate(CoreRoutes::APPEARANCE),
            'mode' => $this->theme->appearance(), 'modes' => ['system', 'light', 'dark'],
            'error' => $error, 'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
