<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/admin/mail` shows how mail is sent (never the SMTP password) and warns when PHP `mail()` is used; POST
 * (CSRF-checked, `admin.access` via AdminGuard, fresh password confirmation) sends a test mail to the signed-in
 * administrator's own address, synchronously (M02.02a). A failure is answered 503 with the translated
 * "could not send" message; the mailer has logged it without secrets.
 */
final class MailSettingsHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly UserRepository $users,
        private readonly MailSettings $settings,
        private readonly Mailer $mailer,
        private readonly MailComposer $composer,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $action = $this->urls->generate(CoreRoutes::ADMIN_MAIL);
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $user = $this->users->findById((int) $this->subjects->userId($request));

            if (!$this->admin->isFresh()) {
                $error = 'admin_reauth_required';
                $status = Response::HTTP_FORBIDDEN;
            } elseif ($user !== null) {
                try {
                    $this->mailer->send($this->composer->compose('test', 'mail.test.subject', $user->email, [
                        'name' => $user->username,
                    ]));

                    return InstallerPage::redirect($action . '?sent=1');
                } catch (MailDeliveryFailed $failure) {
                    $error = $failure->diagnostic;
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            }
        }

        $smtp = $this->settings->transport === MailSettings::SMTP;
        $html = $this->views->render('admin/mail.twig', [
            'title' => $this->translator->translate('admin.mail.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MAIL),
            'error' => $error === null ? null : $this->translator->translate('diagnostics.' . $error),
            'sent' => $error === null && $request->query->get('sent') === '1',
            'transport' => $smtp
                ? $this->translator->translate('admin.mail.smtp', [
                    'host' => (string) $this->settings->smtpHost,
                    'port' => $this->settings->port(),
                ])
                : $this->translator->translate('admin.mail.native'),
            'native' => !$smtp,
            'from' => $this->settings->fromAddress,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'action' => $action,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
