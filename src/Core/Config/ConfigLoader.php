<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use Nolviko\Core\Logging\IpPolicy;
use Throwable;

/**
 * Reads config/local.php (a PHP file outside public/, written by the installer) and applies environment
 * overrides for Docker. No .env parser: shared hosts have no environment to configure.
 */
final class ConfigLoader
{
    /**
     * @param array<string, string> $environment Usually getenv().
     *
     * @return Config|null Null when the file does not exist (not configured yet).
     *
     * @throws ConfigException With diagnostic IDs naming keys only, never values.
     */
    public static function load(string $file, array $environment): ?Config
    {
        if (!is_file($file)) {
            return null;
        }

        return self::fromArray(self::read($file), $environment);
    }

    /**
     * Validate settings in the local.php shape (the installer checks entered values before writing them).
     *
     * @param array<mixed> $file
     * @param array<string, string> $environment
     *
     * @throws ConfigException With every diagnostic found.
     */
    public static function fromArray(array $file, array $environment = []): Config
    {
        $diagnostics = [];
        $values = ConfigSchema::resolve($file, $environment, $diagnostics);

        if ($diagnostics !== []) {
            throw new ConfigException($diagnostics);
        }

        return new Config(
            $values['database.driver'],
            $values['database.host'],
            $values['database.port'],
            $values['database.name'],
            $values['database.user'],
            new Secret($values['database.password']),
            new Secret($values['app.secret']),
            IpPolicy::from($values['privacy.ip_logging'] ?? IpPolicy::Truncated->value),
            $values['http.trusted_proxies'] ?? [],
            $values['http.hsts'] ?? false,
            $values['http.require_https'] ?? false,
            $values['locale.default'],
            $values['locale.time_zone'],
            $values['forums.max_depth'],
            $values['threads.per_page'],
            $values['posting.ip_interval_seconds'],
            new PostingRules(
                $values['posting.first_posts_moderated'] ?? PostingRules::DEFAULT_FIRST_POSTS,
                $values['antispam.link_min_posts'] ?? PostingRules::DEFAULT_LINK_MIN_POSTS,
                $values['antispam.link_min_age_hours'] ?? PostingRules::DEFAULT_LINK_MIN_AGE_HOURS,
                $values['antispam.new_user_max_links'] ?? PostingRules::DEFAULT_NEW_USER_MAX_LINKS,
                $values['antispam.blocked_words'] ?? [],
                array_map('strtolower', $values['antispam.blocked_domains'] ?? []),
            ),
            new MailSettings(
                $values['mail.transport'] ?? MailSettings::MAIL,
                $values['mail.from_address'],
                $values['mail.from_name'],
                $values['mail.smtp_host'] === null ? null : trim($values['mail.smtp_host']),
                $values['mail.smtp_port'],
                $values['mail.smtp_encryption'] ?? MailSettings::STARTTLS,
                $values['mail.smtp_user'],
                new Secret($values['mail.smtp_password'] ?? ''),
            ),
            $values['app.url'] === null ? null : rtrim($values['app.url'], '/'),
            new RegistrationRules(
                $values['registration.mode'] ?? RegistrationRules::VERIFY,
                $values['registration.min_seconds'] ?? RegistrationRules::DEFAULT_MIN_SECONDS,
                $values['registration.ip_interval_seconds'] ?? RegistrationRules::DEFAULT_NETWORK_SECONDS,
                $values['registration.challenge'] ?? RegistrationRules::CHALLENGE_NONE,
            ),
            self::oauth($values),
        );
    }

    /**
     * OAuth clients with both an ID and a secret (2026-11-15); a half-configured provider stays off.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, array{client_id: string, client_secret: Secret}>
     */
    private static function oauth(array $values): array
    {
        $id = trim((string) ($values['oauth.google.client_id'] ?? ''));
        $secret = (string) ($values['oauth.google.client_secret'] ?? '');

        return $id !== '' && $secret !== '' ? ['google' => ['client_id' => $id, 'client_secret' => new Secret($secret)]]
            : [];
    }

    /**
     * @return array<mixed>
     *
     * @throws ConfigException When the file does not return an array or cannot be executed.
     */
    private static function read(string $file): array
    {
        try {
            $data = (static fn(string $path): mixed => require $path)($file);
        } catch (Throwable) {
            // The parser message could quote a value from the file, so it is deliberately dropped.
            throw new ConfigException(['config_file_invalid']);
        }

        if (!is_array($data)) {
            throw new ConfigException(['config_file_invalid']);
        }

        return $data;
    }
}
