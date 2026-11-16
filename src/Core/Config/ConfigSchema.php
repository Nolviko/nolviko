<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use DateTimeZone;
use Nolviko\Core\Logging\IpPolicy;

/**
 * The typed schema of config/local.php and the environment variables that may override it.
 *
 * ```php
 * return [
 *     'database' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => 'forum',
 *         'user' => 'forum', 'password' => '...'],
 *     'app' => ['secret' => '<64 hex characters>', 'url' => 'https://forum.example.com'], // url: links in mail
 *     'privacy' => ['ip_logging' => 'truncated'],  // optional: full, truncated (default) or none
 *     'http' => [                                  // optional
 *         'trusted_proxies' => ['10.0.0.0/8'],     // IPs/CIDRs whose X-Forwarded-For/Proto/Port count
 *         'hsts' => false,                         // send Strict-Transport-Security on HTTPS
 *         'require_https' => false,                // redirect sensitive routes to HTTPS
 *     ],
 *     'registration' => ['mode' => 'verify', 'min_seconds' => 3, 'ip_interval_seconds' => 120,
 *         'challenge' => 'none'],                 // optional (M02.03a); modes open, verify, approval, closed
 *     'mail' => [                                  // optional (ADR-028); PHP mail() when no SMTP is set
 *         'transport' => 'smtp',                   // mail (default) or smtp
 *         'from_address' => 'forum@example.com', 'from_name' => 'Example forum',
 *         'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'starttls', // or smtps
 *         'smtp_user' => 'forum@example.com', 'smtp_password' => '...',
 *     ],

 * ];
 * ```
 */
final class ConfigSchema
{
    public const STRING = 'string';

    public const INTEGER = 'int';

    public const BOOLEAN = 'bool';

    public const STRING_LIST = 'list<string>';

    /** Environment spellings of booleans; anything else is a type error. */
    private const ENVIRONMENT_BOOLEANS = ['1' => true, 'true' => true, 'on' => true, 'yes' => true, '0' => false,
        'false' => false, 'off' => false, 'no' => false];

    /** Runtime-policy driver names (DATABASE_PORTABILITY.md). */
    public const DRIVERS = ['mysql', 'mariadb', 'postgresql'];

    /** M01.14 generates 32 random bytes; stored as text it is at least this long. */
    public const APP_SECRET_MIN_BYTES = 32;

    /** Upper bound for `forums.max_depth` (deeper trees are unusable for people). */
    public const FORUM_MAX_DEPTH_LIMIT = 32;

    /** ADR-014: posts per thread page, a site setting (no per-user value, so permalinks match for everyone). */
    public const THREADS_PER_PAGE_MIN = 10;

    public const THREADS_PER_PAGE_MAX = 50;

    /** M03.15: seconds between posts from one client network; 0 turns the network limit off. */
    public const POSTING_IP_INTERVAL_MAX = 3600;

    /** M03.15a: [minimum, maximum] of the integer anti-spam settings. */
    public const POSTING_RULE_RANGES = [
        'posting.first_posts_moderated' => [1, 100],
        'antispam.link_min_posts' => [0, 1000],
        'antispam.link_min_age_hours' => [0, 8760],
        'antispam.new_user_max_links' => [0, 50],
    ];

    /** A host name for `antispam.blocked_domains`: labels of letters, digits and hyphens. */
    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
        . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/Di';

    /** Catalog locale form (`en`, `tr`, `pt_BR`), as in TranslatorFactory. */
    public const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?$/D';

    /**
     * Dotted key => [type, required, environment override].
     *
     * @var array<string, array{0: string, 1: bool, 2: string|null}>
     */
    public const KEYS = [
        'database.driver' => [self::STRING, true, 'DB_DRIVER'],
        'database.host' => [self::STRING, true, 'DB_HOST'],
        'database.port' => [self::INTEGER, false, 'DB_PORT'],
        'database.name' => [self::STRING, true, 'DB_DATABASE'],
        'database.user' => [self::STRING, true, 'DB_USERNAME'],
        'database.password' => [self::STRING, true, 'DB_PASSWORD'],
        'app.secret' => [self::STRING, true, 'FORUM_APP_SECRET'],
        'app.url' => [self::STRING, false, 'FORUM_APP_URL'],
        'privacy.ip_logging' => [self::STRING, false, 'FORUM_IP_LOGGING'],
        'http.trusted_proxies' => [self::STRING_LIST, false, 'FORUM_TRUSTED_PROXIES'],
        'http.hsts' => [self::BOOLEAN, false, 'FORUM_HSTS'],
        'http.require_https' => [self::BOOLEAN, false, 'FORUM_REQUIRE_HTTPS'],
        'locale.default' => [self::STRING, false, 'FORUM_DEFAULT_LOCALE'],
        'locale.time_zone' => [self::STRING, false, 'FORUM_TIME_ZONE'],
        'forums.max_depth' => [self::INTEGER, false, 'FORUM_MAX_DEPTH'],
        'threads.per_page' => [self::INTEGER, false, 'FORUM_THREADS_PER_PAGE'],
        'posting.ip_interval_seconds' => [self::INTEGER, false, 'FORUM_POSTING_IP_INTERVAL'],
        'posting.first_posts_moderated' => [self::INTEGER, false, 'FORUM_FIRST_POSTS_MODERATED'],
        'antispam.link_min_posts' => [self::INTEGER, false, 'FORUM_LINK_MIN_POSTS'],
        'antispam.link_min_age_hours' => [self::INTEGER, false, 'FORUM_LINK_MIN_AGE_HOURS'],
        'antispam.new_user_max_links' => [self::INTEGER, false, 'FORUM_NEW_USER_MAX_LINKS'],
        'antispam.blocked_words' => [self::STRING_LIST, false, 'FORUM_BLOCKED_WORDS'],
        'antispam.blocked_domains' => [self::STRING_LIST, false, 'FORUM_BLOCKED_DOMAINS'],
        'registration.mode' => [self::STRING, false, 'FORUM_REGISTRATION_MODE'],
        'registration.min_seconds' => [self::INTEGER, false, 'FORUM_REGISTRATION_MIN_SECONDS'],
        'registration.ip_interval_seconds' => [self::INTEGER, false, 'FORUM_REGISTRATION_IP_INTERVAL'],
        'registration.challenge' => [self::STRING, false, 'FORUM_REGISTRATION_CHALLENGE'],
        'mail.transport' => [self::STRING, false, 'FORUM_MAIL_TRANSPORT'],
        'mail.from_address' => [self::STRING, false, 'FORUM_MAIL_FROM'],
        'mail.from_name' => [self::STRING, false, 'FORUM_MAIL_FROM_NAME'],
        'mail.smtp_host' => [self::STRING, false, 'FORUM_SMTP_HOST'],
        'mail.smtp_port' => [self::INTEGER, false, 'FORUM_SMTP_PORT'],
        'mail.smtp_encryption' => [self::STRING, false, 'FORUM_SMTP_ENCRYPTION'],
        'mail.smtp_user' => [self::STRING, false, 'FORUM_SMTP_USER'],
        'mail.smtp_password' => [self::STRING, false, 'FORUM_SMTP_PASSWORD'],
        // Sign in with Google (2026-11-15, nolviko/google extension): only here or in the environment (ADR-034).
        'oauth.google.client_id' => [self::STRING, false, 'FORUM_GOOGLE_CLIENT_ID'],
        'oauth.google.client_secret' => [self::STRING, false, 'FORUM_GOOGLE_CLIENT_SECRET'],
    ];

    /**
     * Flatten, type-check and normalize file values with environment overrides applied.
     *
     * @param array<mixed> $file The array returned by config/local.php.
     * @param array<string, string> $environment
     * @param list<string> $diagnostics Receives every problem found; values are never included.
     *
     * @return array<string, mixed> Dotted key => value; only meaningful when $diagnostics stays empty.
     */
    public static function resolve(array $file, array $environment, array &$diagnostics): array
    {
        $values = [];

        foreach (self::flatten($file, '') as $key => $value) {
            if (isset(self::KEYS[$key])) {
                $values[$key] = $value;
            } elseif (self::isSection($key)) {
                $diagnostics[] = 'config_type_invalid:' . $key;
            } else {
                $diagnostics[] = 'config_key_unknown:' . $key;
            }
        }

        foreach (self::KEYS as $key => [$type, $required, $variable]) {
            if ($variable !== null && isset($environment[$variable]) && $environment[$variable] !== '') {
                $values[$key] = self::fromEnvironment($type, $environment[$variable]);
            }

            if (!array_key_exists($key, $values)) {
                if ($required) {
                    $diagnostics[] = 'config_key_missing:' . $key;
                }

                $values[$key] = null;

                continue;
            }

            if (!self::hasType($type, $values[$key])) {
                $diagnostics[] = 'config_type_invalid:' . $key;
            }
        }

        if ($diagnostics === []) {
            self::checkValues($values, $diagnostics);
        }

        return $values;
    }

    /**
     * Nested sections become dotted keys (`['database' => ['host' => …]]` → `database.host`).
     *
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $name => $value) {
            $key = $prefix . $name;

            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += self::flatten($value, $key . '.');
            } elseif ($value === [] && self::isSection($key)) {
                // An empty section only means its keys are missing; reported as such by resolve().
                continue;
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * `database` given as a scalar is a wrong type for a section, not an unknown key.
     */
    private static function isSection(string $key): bool
    {
        foreach (array_keys(self::KEYS) as $known) {
            if (str_starts_with($known, $key . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Environment values are text: integers only as plain decimal digits, booleans only in the listed
     * spellings, lists as comma-separated items. Anything else stays text and fails the type check.
     */
    private static function fromEnvironment(string $type, string $value): mixed
    {
        return match (true) {
            $type === self::INTEGER && preg_match('/^[0-9]{1,5}$/D', $value) === 1 => (int) $value,
            $type === self::BOOLEAN => self::ENVIRONMENT_BOOLEANS[strtolower(trim($value))] ?? $value,
            $type === self::STRING_LIST => array_values(array_filter(array_map('trim', explode(',', $value)))),
            default => $value,
        };
    }

    private static function hasType(string $type, mixed $value): bool
    {
        return match ($type) {
            self::STRING => is_string($value),
            self::INTEGER => is_int($value) || $value === null,
            self::BOOLEAN => is_bool($value),
            self::STRING_LIST => is_array($value) && array_is_list($value)
                && array_filter($value, 'is_string') === $value,
            default => false,
        };
    }

    /**
     * An IP address or CIDR range (IPv4 /0–32, IPv6 /0–128).
     */
    private static function isAddressOrRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($packed) * 8);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $diagnostics
     */
    private static function checkValues(array $values, array &$diagnostics): void
    {
        if (!in_array($values['database.driver'], self::DRIVERS, true)) {
            $diagnostics[] = 'config_value_invalid:database.driver';
        }

        foreach (['database.host', 'database.name', 'database.user'] as $key) {
            if (trim((string) $values[$key]) === '') {
                $diagnostics[] = 'config_value_invalid:' . $key;
            }
        }

        if ($values['database.port'] !== null && ($values['database.port'] < 1 || $values['database.port'] > 65535)) {
            $diagnostics[] = 'config_value_invalid:database.port';
        }

        if (strlen((string) $values['app.secret']) < self::APP_SECRET_MIN_BYTES) {
            $diagnostics[] = 'config_value_invalid:app.secret';
        }

        if ($values['locale.default'] !== null && preg_match(self::LOCALE_PATTERN, $values['locale.default']) !== 1) {
            $diagnostics[] = 'config_value_invalid:locale.default';
        }

        $maxDepth = $values['forums.max_depth'];

        if ($maxDepth !== null && ($maxDepth < 1 || $maxDepth > self::FORUM_MAX_DEPTH_LIMIT)) {
            $diagnostics[] = 'config_value_invalid:forums.max_depth';
        }

        $perPage = $values['threads.per_page'];

        if ($perPage !== null && ($perPage < self::THREADS_PER_PAGE_MIN || $perPage > self::THREADS_PER_PAGE_MAX)) {
            $diagnostics[] = 'config_value_invalid:threads.per_page';
        }

        $ipInterval = $values['posting.ip_interval_seconds'];

        if ($ipInterval !== null && ($ipInterval < 0 || $ipInterval > self::POSTING_IP_INTERVAL_MAX)) {
            $diagnostics[] = 'config_value_invalid:posting.ip_interval_seconds';
        }

        foreach (self::POSTING_RULE_RANGES as $key => [$minimum, $maximum]) {
            if ($values[$key] !== null && ($values[$key] < $minimum || $values[$key] > $maximum)) {
                $diagnostics[] = 'config_value_invalid:' . $key;
            }
        }

        foreach ($values['antispam.blocked_domains'] ?? [] as $domain) {
            if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
                $diagnostics[] = 'config_value_invalid:antispam.blocked_domains';

                break;
            }
        }

        foreach ($values['antispam.blocked_words'] ?? [] as $word) {
            if (trim($word) === '' || !mb_check_encoding($word, 'UTF-8')) {
                $diagnostics[] = 'config_value_invalid:antispam.blocked_words';

                break;
            }
        }

        $timeZone = $values['locale.time_zone'];

        if ($timeZone !== null && !in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            $diagnostics[] = 'config_value_invalid:locale.time_zone';
        }

        if ($values['privacy.ip_logging'] !== null && IpPolicy::tryFrom($values['privacy.ip_logging']) === null) {
            $diagnostics[] = 'config_value_invalid:privacy.ip_logging';
        }

        foreach ($values['http.trusted_proxies'] ?? [] as $proxy) {
            if (!self::isAddressOrRange($proxy)) {
                $diagnostics[] = 'config_value_invalid:http.trusted_proxies';

                break;
            }
        }

        self::checkMail($values, $diagnostics);

        foreach (RegistrationRules::RANGES as $key => [$minimum, $maximum]) {
            if ($values[$key] !== null && ($values[$key] < $minimum || $values[$key] > $maximum)) {
                $diagnostics[] = 'config_value_invalid:' . $key;
            }
        }

        $mode = $values['registration.mode'];

        if ($mode !== null && !in_array($mode, RegistrationRules::MODES, true)) {
            $diagnostics[] = 'config_value_invalid:registration.mode';
        }

        $challenge = $values['registration.challenge'];

        if ($challenge !== null && !in_array($challenge, RegistrationRules::CHALLENGES, true)) {
            $diagnostics[] = 'config_value_invalid:registration.challenge';
        }

        if ($values['app.url'] !== null && !self::isBaseUrl($values['app.url'])) {
            $diagnostics[] = 'config_value_invalid:app.url';
        }
    }

    /**
     * M02.03: the site's own absolute address for links in mail (never taken from the request's Host header):
     * http or https, a host, an optional port and path, no credentials, query or fragment.
     */
    public static function isBaseUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !str_contains($url, '?')
            && !str_contains($url, '#')
            && preg_match('/[\x00-\x20\x7F\\\\]/', $url) !== 1;
    }

    /**
     * ADR-028: `mail` or `smtp`; SMTP needs a host and always uses TLS (no plaintext option exists).
     *
     * @param array<string, mixed> $values
     * @param list<string> $diagnostics
     */
    private static function checkMail(array $values, array &$diagnostics): void
    {
        $transport = $values['mail.transport'] ?? MailSettings::MAIL;

        if (!in_array($transport, MailSettings::TRANSPORTS, true)) {
            $diagnostics[] = 'config_value_invalid:mail.transport';
        }

        $from = $values['mail.from_address'];

        if ($from !== null && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $diagnostics[] = 'config_value_invalid:mail.from_address';
        }

        $name = $values['mail.from_name'];

        if ($name !== null && (!mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1)) {
            $diagnostics[] = 'config_value_invalid:mail.from_name';
        }

        $host = trim((string) $values['mail.smtp_host']);

        if ($transport === MailSettings::SMTP && ($host === '' || preg_match('/[\s\/@:]/', $host) === 1)) {
            $diagnostics[] = 'config_value_invalid:mail.smtp_host';
        }

        $port = $values['mail.smtp_port'];

        if ($port !== null && ($port < 1 || $port > 65535)) {
            $diagnostics[] = 'config_value_invalid:mail.smtp_port';
        }

        $encryption = $values['mail.smtp_encryption'];

        if ($encryption !== null && !in_array($encryption, MailSettings::ENCRYPTIONS, true)) {
            $diagnostics[] = 'config_value_invalid:mail.smtp_encryption';
        }
    }
}
