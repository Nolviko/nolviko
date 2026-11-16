<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use Nolviko\Core\Logging\IpPolicy;

/**
 * Validated, typed site configuration (see ConfigSchema). Immutable; secrets are Secret objects.
 */
final class Config
{
    public function __construct(
        public readonly string $databaseDriver,
        public readonly string $databaseHost,
        public readonly ?int $databasePort,
        public readonly string $databaseName,
        public readonly string $databaseUser,
        public readonly Secret $databasePassword,
        public readonly Secret $appSecret,
        public readonly IpPolicy $ipLogging = IpPolicy::Truncated,
        /** @var list<string> */
        public readonly array $trustedProxies = [],
        public readonly bool $hsts = false,
        public readonly bool $requireHttps = false,
        public readonly ?string $defaultLocale = null,
        public readonly ?string $defaultTimeZone = null,
        public readonly ?int $forumMaxDepth = null,
        public readonly ?int $threadsPerPage = null,
        public readonly ?int $postingIpInterval = null,
        public readonly PostingRules $postingRules = new PostingRules(),
        public readonly MailSettings $mail = new MailSettings(),
        /** Absolute base URL for links in mail, without a trailing slash (M02.03). */
        public readonly ?string $appUrl = null,
        public readonly RegistrationRules $registration = new RegistrationRules(),
        /** @var array<string, array{client_id: string, client_secret: Secret}> OAuth clients by provider (2026-11-15). */
        public readonly array $oauth = [],
    ) {
    }

    /**
     * Redacted view for dumps and diagnostics; the only array form of the configuration.
     *
     * @return array<string, array<string, string|int|null>>
     */
    public function redacted(): array
    {
        return [
            'database' => [
                'driver' => $this->databaseDriver,
                'host' => $this->databaseHost,
                'port' => $this->databasePort,
                'name' => $this->databaseName,
                'user' => $this->databaseUser,
                'password' => Secret::REDACTED,
            ],
            'app' => [
                'secret' => Secret::REDACTED,
            ],
            'privacy' => [
                'ip_logging' => $this->ipLogging->value,
            ],
            'http' => [
                'trusted_proxies' => implode(',', $this->trustedProxies),
                'hsts' => (int) $this->hsts,
                'require_https' => (int) $this->requireHttps,
            ],
            'mail' => [
                'transport' => $this->mail->transport,
                'from_address' => $this->mail->fromAddress,
                'smtp_host' => $this->mail->smtpHost,
                'smtp_port' => $this->mail->smtpPort,
                'smtp_encryption' => $this->mail->smtpEncryption,
                'smtp_user' => $this->mail->smtpUser,
                'smtp_password' => Secret::REDACTED,
            ],
            'oauth' => [
                'google_client_id' => $this->oauth['google']['client_id'] ?? null,
                'google_client_secret' => isset($this->oauth['google']) ? Secret::REDACTED : null,
            ],
        ];
    }

    /**
     * @return array<string, array<string, string|int|null>>
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }
}
