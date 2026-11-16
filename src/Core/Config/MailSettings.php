<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

/**
 * Outgoing mail settings (ADR-028, M02.02a): PHP `mail()` by default, or SMTP with required TLS (STARTTLS on
 * 587 or implicit TLS on 465). The SMTP password is a Secret like the database password.
 */
final class MailSettings
{
    public const MAIL = 'mail';

    public const SMTP = 'smtp';

    public const TRANSPORTS = [self::MAIL, self::SMTP];

    public const STARTTLS = 'starttls';

    public const SMTPS = 'smtps';

    public const ENCRYPTIONS = [self::STARTTLS, self::SMTPS];

    public readonly Secret $smtpPassword;

    public function __construct(
        public readonly string $transport = self::MAIL,
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $smtpHost = null,
        public readonly ?int $smtpPort = null,
        public readonly string $smtpEncryption = self::STARTTLS,
        public readonly ?string $smtpUser = null,
        ?Secret $smtpPassword = null,
    ) {
        $this->smtpPassword = $smtpPassword ?? new Secret('');
    }

    /**
     * The port to connect to: the configured one, else 465 for implicit TLS and 587 for STARTTLS.
     */
    public function port(): int
    {
        return $this->smtpPort ?? ($this->smtpEncryption === self::SMTPS ? 465 : 587);
    }
}
