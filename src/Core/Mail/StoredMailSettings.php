<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use SensitiveParameter;

/**
 * Outgoing mail set from Admin > Outgoing mail (ADR-034 amendment 2026-11-21, owner rule: every setting in the
 * panel). The SMTP rows live in the `settings` table; the password is sealed (libsodium secretbox) with a key
 * derived from `app.secret` and is never shown again. Precedence as for every ADR-034 setting: when
 * `config/local.php` or the environment selects SMTP, the whole SMTP block comes from there (read-only in the
 * panel); otherwise the stored values apply; otherwise PHP `mail()`.
 */
final class StoredMailSettings
{
    /** Settings rows of the SMTP block (the sender rows mail.from_* are ordinary ADR-034 keys). */
    public const SMTP_KEYS = ['mail.transport', 'mail.smtp_host', 'mail.smtp_port', 'mail.smtp_encryption',
        'mail.smtp_user', 'mail.smtp_password'];

    /** Longest password accepted before sealing. */
    public const PASSWORD_MAX = 512;

    private const PREFIX = 'v1:';

    /** True when `config/local.php` / the environment selects SMTP, so the panel shows the block read-only. */
    public static function pinned(?Config $config): bool
    {
        return $config?->mail->transport === MailSettings::SMTP;
    }

    /**
     * The effective settings.
     *
     * @param array<string, string> $stored Settings rows by key (missing keys: not set).
     */
    public static function resolve(?Config $config, array $stored): MailSettings
    {
        $mail = $config?->mail ?? new MailSettings();
        $from = $mail->fromAddress ?? ($stored['mail.from_address'] ?? null);
        $name = $mail->fromName ?? ($stored['mail.from_name'] ?? null);
        $host = $stored['mail.smtp_host'] ?? '';

        if (
            $config === null || self::pinned($config) || $host === ''
            || ($stored['mail.transport'] ?? MailSettings::MAIL) !== MailSettings::SMTP
        ) {
            return new MailSettings(
                $mail->transport,
                $from,
                $name,
                $mail->smtpHost,
                $mail->smtpPort,
                $mail->smtpEncryption,
                $mail->smtpUser,
                $mail->smtpPassword,
            );
        }

        $sealed = $stored['mail.smtp_password'] ?? null;
        $encryption = $stored['mail.smtp_encryption'] ?? MailSettings::STARTTLS;

        return new MailSettings(
            MailSettings::SMTP,
            $from,
            $name,
            $host,
            isset($stored['mail.smtp_port']) ? (int) $stored['mail.smtp_port'] : null,
            in_array($encryption, MailSettings::ENCRYPTIONS, true) ? $encryption : MailSettings::STARTTLS,
            $stored['mail.smtp_user'] ?? null,
            new Secret($sealed === null ? '' : (string) self::open($config->appSecret, $sealed)),
        );
    }

    public static function seal(Secret $appSecret, #[SensitiveParameter] string $password): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($password, $nonce, self::key($appSecret)));
    }

    /** The password, or null when the value is not readable (for example after `app.secret` changed). */
    public static function open(Secret $appSecret, string $sealed): ?string
    {
        $raw = str_starts_with($sealed, self::PREFIX) ? base64_decode(substr($sealed, strlen(self::PREFIX)), true)
            : false;

        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key($appSecret),
        );

        return is_string($plain) ? $plain : null;
    }

    private static function key(Secret $appSecret): string
    {
        return hash_hkdf('sha256', $appSecret->reveal(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'nolviko smtp password');
    }
}
