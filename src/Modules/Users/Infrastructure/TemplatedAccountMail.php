<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Users\Domain\AccountMail;

/**
 * AccountMail through the Mailer and the mail templates. Links are `app.url` plus a generated route path; without
 * `app.url` no account mail is sent (`mail_not_configured`), because a link built from the request's Host header
 * could point a verification token at someone else's server.
 */
final class TemplatedAccountMail implements AccountMail
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly MailComposer $composer,
        private readonly UrlGenerator $urls,
        private readonly ?string $siteUrl,
    ) {
    }

    public function verification(string $to, string $username, string $token): void
    {
        $this->mailer->send($this->composer->compose('verify_email', 'mail.verify_email.subject', $to, [
            'name' => $username,
            'url' => $this->link(CoreRoutes::VERIFY_EMAIL) . '?token=' . rawurlencode($token),
        ]));
    }

    public function alreadyRegistered(string $to, string $username): void
    {
        $this->mailer->send($this->composer->compose('already_registered', 'mail.already_registered.subject', $to, [
            'name' => $username,
            'login_url' => $this->link(CoreRoutes::LOGIN),
            'resend_url' => $this->link(CoreRoutes::VERIFY_RESEND),
        ]));
    }

    public function passwordReset(string $to, string $username, string $token): void
    {
        $this->mailer->send($this->composer->compose('password_reset', 'mail.password_reset.subject', $to, [
            'name' => $username,
            'url' => $this->link(CoreRoutes::PASSWORD_RESET) . '?token=' . rawurlencode($token),
        ]));
    }

    public function emailChangeConfirm(string $to, string $username, string $token): void
    {
        $this->mailer->send($this->composer->compose('email_change', 'mail.email_change.subject', $to, [
            'name' => $username,
            'url' => $this->link(CoreRoutes::ACCOUNT_EMAIL_CONFIRM) . '?token=' . rawurlencode($token),
        ]));
    }

    public function emailChangeNotice(string $to, string $username): void
    {
        $this->mailer->send($this->composer->compose('email_change_notice', 'mail.email_change_notice.subject', $to, [
            'name' => $username,
            'url' => $this->link(CoreRoutes::ACCOUNT),
        ]));
    }

    /**
     * A second sign-in step was turned on, off, or reset by an administrator (2026-11-15).
     *
     * @param 'enabled'|'disabled'|'reset' $event
     */
    public function secondFactorNotice(string $to, string $username, string $event): void
    {
        $this->mailer->send($this->composer->compose('second_factor', 'mail.second_factor.subject', $to, [
            'name' => $username,
            'event' => in_array($event, ['enabled', 'disabled', 'reset'], true) ? $event : 'disabled',
            'url' => $this->link(CoreRoutes::ACCOUNT),
        ]));
    }

    private function link(string $route): string
    {
        if ($this->siteUrl === null) {
            throw new MailDeliveryFailed('mail_not_configured');
        }

        $path = $this->urls->generate($route);
        $base = rtrim($this->siteUrl, '/');

        // UrlGenerator paths include the request's base path; `app.url` already ends at the installation folder.
        $basePath = (string) parse_url($base, PHP_URL_PATH);

        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }

        return $base . $path;
    }
}
