<?php

declare(strict_types=1);

namespace Nolviko\Core\Settings;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Config\ConfigSchema;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Locale\LocaleFormatter;
use Nolviko\Core\View\ThemeTokens;
use Throwable;

/**
 * Administrator-editable, non-secret settings stored in the `settings` table (ADR-034). One whitelist defines every
 * key with its type and limits; nothing else is read or written here. Reads come from a cache entry that a save
 * removes. When the database cannot be reached the store answers "no stored values", so every page still renders
 * with `config/local.php` and the built-in defaults.
 */
final class SiteSettings
{
    /** Home page layouts (2026-11-08): the board index, or the latest discussions across all forums. */
    public const HOME_LAYOUTS = ['classic', 'discussions'];

    /** Built-in value of each enum setting while nothing is stored (selected in /admin/site). */
    public const DEFAULTS = ['registration.mode' => 'verify', 'registration.challenge' => 'none',
        'home.layout' => 'classic', 'appearance.accent' => 'green', 'gallery.enabled' => 'off',
        'gallery.approval' => 'off', 'api.enabled' => 'on', 'posting.images' => 'off',
        'seo.indexnow' => 'off'];

    /** Key => [type, limit] (string/email: max length, int: [min, max], enum: options). */
    public const KEYS = [
        'site.name' => ['string', 80],
        'site.description' => ['string', 300],
        // Owner rule 2026-11-17: every site setting is reachable from the panel; empty means automatic.
        'locale.default' => ['locale', 16],
        'locale.time_zone' => ['timezone', 64],
        'registration.mode' => ['enum', RegistrationRules::MODES],
        'registration.min_seconds' => ['int', [0, 60]],
        'registration.challenge' => ['enum', RegistrationRules::CHALLENGES],
        'mail.from_address' => ['email', 254],
        'mail.from_name' => ['string', 100],
        // ADR-034 amendment 2026-11-21: SMTP from Admin > Outgoing mail; the password row holds a sealed value.
        'mail.transport' => ['enum', ['mail', 'smtp']],
        'mail.smtp_host' => ['host', 253],
        'mail.smtp_port' => ['int', [1, 65535]],
        'mail.smtp_encryption' => ['enum', ['starttls', 'smtps']],
        'mail.smtp_user' => ['string', 254],
        'mail.smtp_password' => ['sealed', 2048],
        'home.layout' => ['enum', self::HOME_LAYOUTS],
        'appearance.accent' => ['enum', ['green', 'blue', 'yellow', 'red', 'purple']],
        'announcement.text' => ['string', 300],
        'api.enabled' => ['enum', ['on', 'off']],
        'seo.indexnow' => ['enum', ['off', 'on']],
        // Owner decision 2026-11-14: gallery albums and images in posts are separate switches, both off at first.
        'gallery.enabled' => ['enum', ['off', 'on']],
        'gallery.max_mb' => ['int', [1, 100]],
        'gallery.extensions' => ['string', 100],
        'gallery.quota_mb' => ['int', [0, 1000000]],
        'gallery.approval' => ['enum', ['off', 'on']],
        'posting.images' => ['enum', ['off', 'on']],
        'posting.image_max_mb' => ['int', [1, 100]],
        'posting.images_per_post' => ['int', [1, 50]],
        'antispam.blocked_words' => ['string', 2000],
        'antispam.censored_words' => ['string', 2000],
        'antispam.blocked_domains' => ['string', 2000],
        'antispam.new_user_max_links' => ['int', [0, 50]],
        'antispam.link_min_posts' => ['int', [0, 1000]],
        'antispam.first_posts_moderated' => ['int', [0, 100]],
    ];

    private const CACHE_KEY = 'site-settings-v1';

    /** Seconds stored values stay cached; only save() changes them and it writes through (2026-11-17). */
    private const TTL = 2592000;

    /** Seconds an unreadable database is remembered as "no stored values". */
    private const FAILURE_TTL = 30;

    /** @var array<string, string>|null */
    private ?array $values = null;

    /**
     * @param Closure(): Connection $connection Built only when the cache misses or on save.
     */
    /** Keys every request may need before it touches the database; also kept in the boot file (2026-11-17). */
    public const BOOT_KEYS = ['locale.default', 'locale.time_zone'];

    /**
     * @param string|null $bootFile Small JSON copy of BOOT_KEYS that survives cache clears and updates; null: none.
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly Cache $cache,
        private readonly ?string $bootFile = null,
    ) {
    }

    /**
     * @param bool $connect False for display-only reads (page header): the database is asked only when this request
     *     already holds an open connection, so pages that need no database never open one for the site name.
     * @return array<string, string> Stored values by key; keys without a row are absent.
     */
    public function values(bool $connect = true): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->values = array_filter($cached, 'is_string');
        }

        try {
            $db = ($this->connection)();

            if (!$connect && !$db->isConnected()) {
                return [];
            }

            $rows = $db->fetchAllKeyValue(
                'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?)',
                [array_keys(self::KEYS)],
                [ArrayParameterType::STRING],
            );
        } catch (Throwable) {
            // Remember the failure briefly, so an unreachable database does not add a connect timeout to every page.
            $this->cache->set(self::CACHE_KEY, [], self::FAILURE_TTL);

            return $this->values = [];
        }

        $values = [];

        foreach ($rows as $key => $value) {
            $values[(string) $key] = (string) $value;
        }

        $this->cache->set(self::CACHE_KEY, $values, self::TTL);
        // Sites that chose a language before the boot file existed get one on the next database read.
        $boot = array_intersect_key($values, array_flip(self::BOOT_KEYS));
        if ($this->bootFile !== null && $this->readBoot() !== $boot) {
            $this->writeBoot($values);
        }

        return $this->values = $values;
    }

    public function get(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    /**
     * Cache-only read (2026-11-17): never builds or opens a connection, for values every request needs before it
     * knows whether it touches the database (the page language). save() writes the cache through, and any full
     * values() read refills it after it expires.
     */
    public function cached(string $key): ?string
    {
        $values = $this->values ?? $this->cache->get(self::CACHE_KEY);
        if (!is_array($values) && in_array($key, self::BOOT_KEYS, true)) {
            // Cache cleared (an update, a cache reset): the boot file still knows the page language.
            $values = $this->readBoot();
        }

        return is_array($values) && is_string($values[$key] ?? null) ? $values[$key] : null;
    }

    /** @return array<string, string> */
    private function readBoot(): array
    {
        if ($this->bootFile === null || is_link($this->bootFile) || !is_file($this->bootFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->bootFile), true, 4);

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    /** @param array<string, string> $values */
    private function writeBoot(array $values): void
    {
        if ($this->bootFile === null) {
            return;
        }
        $directory = dirname($this->bootFile);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
            return;
        }
        $boot = array_intersect_key($values, array_flip(self::BOOT_KEYS));
        $temporary = $this->bootFile . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, json_encode($boot, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            @rename($temporary, $this->bootFile) || @unlink($temporary);
        }
    }

    /** Display-only read: never opens a database connection (see values()). */
    public function peek(string $key): ?string
    {
        return $this->values(false)[$key] ?? null;
    }

    /**
     * Validates every submitted value, then writes the changed ones (an empty value removes the row) with one
     * `audit_logs` row, all in one transaction, and drops the cache entry.
     *
     * @param array<string, string> $submitted Key => raw form value; unknown keys are refused.
     * @param list<string> $pinned Keys that `config/local.php` sets; they are never written here.
     * @return int Number of changed settings.
     * @throws InvalidArgumentException `setting_invalid:<key>`.
     */
    public function save(array $submitted, array $pinned, int $actorId): int
    {
        $current = $this->values();
        $changes = [];

        foreach ($submitted as $key => $raw) {
            if (!isset(self::KEYS[$key])) {
                throw new InvalidArgumentException('setting_invalid:' . $key);
            }

            if (in_array($key, $pinned, true)) {
                continue;
            }

            $value = self::normalize($key, $raw);

            if ($value !== ($current[$key] ?? null)) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return 0;
        }

        $db = ($this->connection)();
        $db->transactional(static function (Connection $db) use ($changes, $actorId): void {
            $now = (string) $db->fetchOne('SELECT UTC_TIMESTAMP(6)');

            foreach ($changes as $key => $value) {
                $db->delete('settings', ['setting_key' => $key]);

                if ($value !== null) {
                    $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value,
                        'value_type' => self::KEYS[$key][0] === 'int' ? 'int' : 'string', 'updated_at' => $now]);
                }
            }

            $db->insert('audit_logs', ['actor_id' => $actorId, 'action' => 'admin.settings_changed',
                'target_type' => 'settings', 'target_id' => 0, 'reason' => null,
                'details' => json_encode(['changed' => array_keys($changes)], JSON_THROW_ON_ERROR),
                'created_at' => $now]);
        });
        // Write through, so cache-only readers (the page language) see the change on the next request.
        $fresh = array_filter(array_merge($current, $changes), 'is_string');
        $this->cache->set(self::CACHE_KEY, $fresh, self::TTL);
        $this->values = $fresh;
        if (array_intersect(array_keys($changes), self::BOOT_KEYS) !== []) {
            $this->writeBoot($fresh);
        }

        return count($changes);
    }

    /**
     * The stored form of a raw value, or null for "not set".
     *
     * @throws InvalidArgumentException `setting_invalid:<key>`.
     */
    public static function normalize(string $key, string $raw): ?string
    {
        [$type, $limit] = self::KEYS[$key] ?? throw new InvalidArgumentException('setting_invalid:' . $key);
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        $valid = match ($type) {
            'string' => mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $limit
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1,
            'email' => strlen($value) <= $limit && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'int' => preg_match('/^[0-9]{1,6}$/D', $value) === 1 && (int) $value >= $limit[0]
                && (int) $value <= $limit[1],
            'enum' => in_array($value, $limit, true),
            'locale' => strlen($value) <= $limit && preg_match(ConfigSchema::LOCALE_PATTERN, $value) === 1,
            'timezone' => strlen($value) <= $limit && LocaleFormatter::isTimeZone($value),
            'host' => strlen($value) <= $limit
                && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $value) === 1,
            'sealed' => strlen($value) <= $limit && preg_match('/^v1:[A-Za-z0-9+\/]+={0,2}$/D', $value) === 1,
            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException('setting_invalid:' . $key);
        }

        return $type === 'int' ? (string) (int) $value : $value;
    }
}
