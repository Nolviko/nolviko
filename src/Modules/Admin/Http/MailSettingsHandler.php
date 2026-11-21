<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Mail\StoredMailSettings;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/admin/mail`: how mail is sent, and (ADR-034 amendment 2026-11-21) the SMTP server, port, encryption, user,
 * password and sender, all editable here. Every POST is CSRF-checked and needs `admin.access` (AdminGuard and
 * again here). `store=1` stores the form through SiteSettings (one audit row; the password sealed with the
 * app secret, never shown again, kept when the field stays empty); any other POST sends a test mail to the
 * signed-in administrator's own address with the settings of this request. Values that `config/local.php` sets
 * are shown read-only and never written.
 */
final class MailSettingsHandler implements RouteHandler
{
    /** Form field => settings key. */
    private const FIELDS = ['transport' => 'mail.transport', 'host' => 'mail.smtp_host', 'port' => 'mail.smtp_port',
        'encryption' => 'mail.smtp_encryption', 'user' => 'mail.smtp_user', 'from_address' => 'mail.from_address',
        'from_name' => 'mail.from_name'];

    /** Settings key => label message ID for the "check this field" error. */
    private const LABELS = ['mail.transport' => 'admin.mail.field.transport',
        'mail.smtp_host' => 'admin.mail.field.host',
        'mail.smtp_port' => 'admin.mail.field.port', 'mail.smtp_encryption' => 'admin.mail.field.encryption',
        'mail.smtp_user' => 'admin.mail.field.user', 'mail.smtp_password' => 'admin.mail.field.password',
        'mail.from_address' => 'admin.mail.field.from_address', 'mail.from_name' => 'admin.mail.field.from_name'];

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
        private readonly ?SiteSettings $site = null,
        private readonly ?Config $config = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $action = $this->urls->generate(CoreRoutes::ADMIN_MAIL);
        $error = null;
        $status = Response::HTTP_OK;
        $input = $request->request->all();
        $form = null;
        $reply = null;

        if ($request->isMethod('POST')) {
            $subject = $this->subjects->of($request);
            $token = is_string($input[CsrfTokens::FIELD] ?? null) ? $input[CsrfTokens::FIELD] : '';

            if (
                !$this->csrf->isValid($token) || !$this->admin->isFresh() || $subject->userId === null
                || !$subject->allows('admin.access')
            ) {
                $error = $this->translator->translate('admin.mail.denied');
                $status = Response::HTTP_FORBIDDEN;
            } elseif (($input['store'] ?? null) === '1') {
                $form = array_map(
                    static fn(mixed $value): string => is_string($value) ? $value : '',
                    array_intersect_key($input, self::FIELDS),
                );

                try {
                    $changed = $this->save($input, $subject->userId);

                    return InstallerPage::redirect($action . '?saved=' . ($changed === 0 ? '0' : '1'));
                } catch (InvalidArgumentException $failure) {
                    $key = substr($failure->getMessage(), strlen('setting_invalid:'));
                    $error = $this->translator->translate('admin.mail.invalid', [
                        'field' => $this->translator->translate(self::LABELS[$key] ?? 'admin.mail.field.transport'),
                    ]);
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } catch (Throwable) {
                    $error = $this->translator->translate('admin.mail.unavailable');
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            } else {
                $user = $this->users->findById($subject->userId);

                if ($user !== null) {
                    try {
                        $this->mailer->send($this->composer->compose('test', 'mail.test.subject', $user->email, [
                            'name' => $user->username,
                        ]));

                        return InstallerPage::redirect($action . '?sent=1');
                    } catch (MailDeliveryFailed $failure) {
                        $error = $this->translator->translate('diagnostics.' . $failure->diagnostic);
                        // Shown to this administrator only, never logged (the mailer logs the class name).
                        $reply = $failure->serverReply;
                        $status = Response::HTTP_SERVICE_UNAVAILABLE;
                    }
                }
            }
        }

        $context = $this->context($request, $action, $error, $form);
        $context['server_reply'] = $reply;
        $html = $this->views->render('admin/mail.twig', $context);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws InvalidArgumentException `setting_invalid:<key>`.
     */
    private function save(array $input, int $actorId): int
    {
        if ($this->site === null || $this->config === null) {
            throw new InvalidArgumentException('setting_invalid:mail.transport');
        }

        $submitted = [];
        foreach (self::FIELDS as $field => $key) {
            $submitted[$key] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }

        if ($submitted['mail.transport'] === MailSettings::SMTP && trim($submitted['mail.smtp_host']) === '') {
            throw new InvalidArgumentException('setting_invalid:mail.smtp_host');
        }

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($password) > StoredMailSettings::PASSWORD_MAX || preg_match('/[\x00\r\n]/', $password) === 1) {
            throw new InvalidArgumentException('setting_invalid:mail.smtp_password');
        }
        if ($password !== '') {
            $submitted['mail.smtp_password'] = StoredMailSettings::seal($this->config->appSecret, $password);
        } elseif (($input['clear_password'] ?? null) === '1') {
            $submitted['mail.smtp_password'] = '';
        }

        return $this->site->save($submitted, $this->pinned(), $actorId);
    }

    /**
     * Keys that `config/local.php` / the environment sets; they are shown read-only and never written.
     *
     * @return list<string>
     */
    private function pinned(): array
    {
        // The sender is never pinned: the panel value wins over config/local.php (owner decision 2026-11-21).
        return StoredMailSettings::pinned($this->config) ? StoredMailSettings::SMTP_KEYS : [];
    }

    /**
     * @param array<string, string>|null $form Submitted values to show again after a refused save.
     * @return array<string, mixed>
     */
    private function context(Request $request, string $action, ?string $error, ?array $form): array
    {
        $stored = [];
        try {
            $stored = $this->site?->values() ?? [];
        } catch (Throwable) {
            // The page still shows the effective transport and the test button.
        }

        $pinned = $this->pinned();
        $smtpPinned = StoredMailSettings::pinned($this->config);
        $mail = $this->config?->mail;
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$field] = $form[$field] ?? match (true) {
                $smtpPinned && $key === 'mail.transport' => MailSettings::SMTP,
                $smtpPinned && $key === 'mail.smtp_host' => (string) $mail?->smtpHost,
                $smtpPinned && $key === 'mail.smtp_port' => (string) $mail?->port(),
                $smtpPinned && $key === 'mail.smtp_encryption' => (string) $mail?->smtpEncryption,
                $smtpPinned && $key === 'mail.smtp_user' => (string) $mail?->smtpUser,
                default => $stored[$key] ?? match ($key) {
                    'mail.transport' => MailSettings::MAIL,
                    'mail.smtp_encryption' => MailSettings::STARTTLS,
                    default => '',
                },
            };
        }

        $smtp = $this->settings->transport === MailSettings::SMTP;

        return [
            'title' => $this->translator->translate('admin.mail.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MAIL),
            'error' => $error,
            'sent' => $error === null && $request->query->get('sent') === '1',
            'saved' => $error === null ? $request->query->get('saved') : null,
            'transport' => $smtp
                ? $this->translator->translate('admin.mail.smtp', [
                    'host' => (string) $this->settings->smtpHost,
                    'port' => $this->settings->port(),
                ])
                : $this->translator->translate('admin.mail.native'),
            'native' => !$smtp,
            'from' => $this->settings->fromAddress,
            'editable' => $this->site !== null && $this->config !== null,
            'values' => $values,
            'transports' => [
                $this->option(MailSettings::MAIL, 'admin.mail.transport.mail', $values['transport']),
                $this->option(MailSettings::SMTP, 'admin.mail.transport.smtp', $values['transport']),
            ],
            'encryptions' => [
                $this->option(MailSettings::STARTTLS, 'installer.mail.starttls', $values['encryption']),
                $this->option(MailSettings::SMTPS, 'installer.mail.smtps', $values['encryption']),
            ],
            'pinned' => array_fill_keys(array_keys(array_intersect(self::FIELDS, $pinned)), true),
            'smtp_pinned' => $smtpPinned,
            'password_set' => $smtpPinned ? !$this->settings->smtpPassword->isEmpty()
                : isset($stored['mail.smtp_password']),
            'password_max' => StoredMailSettings::PASSWORD_MAX,
            'config_from' => $mail?->fromAddress,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'action' => $action,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ];
    }

    /** @return array{value: string, label: string, selected: bool} */
    private function option(string $value, string $label, string $current): array
    {
        return ['value' => $value, 'label' => $this->translator->translate($label), 'selected' => $value === $current];
    }
}
