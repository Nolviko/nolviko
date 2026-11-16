<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigSchema;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Normalizer;
use RuntimeException;

/** Explicit nonsecret projection of validated configuration; search never enumerates Config or reveals Secret. */
final readonly class SettingsCatalog
{
    public function __construct(
        private ?Config $config,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
    ) {
    }

    /** @return list<array{id: string, label: string}> */
    public function groups(): array
    {
        $labels = ['locale' => 'admin.settings.group_locale', 'forum' => 'admin.settings.group_forum',
            'posting' => 'admin.settings.group_posting', 'registration' => 'admin.settings.group_registration',
            'mail' => 'admin.settings.group_mail', 'security' => 'admin.settings.group_security'];
        $result = [];

        foreach ($labels as $id => $label) {
            $result[] = ['id' => $id, 'label' => $this->translator->translate($label)];
        }

        return $result;
    }

    /** @return list<array{key: string, label: string, group: string, value: string, type: string, url: string|null}> */
    public function search(string $query, string $group = ''): array
    {
        if (
            strlen($query) > 512 || !mb_check_encoding($query, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $query) === 1 || grapheme_strlen($query) > 120
            || ($group !== '' && !in_array($group, array_column($this->groups(), 'id'), true))
        ) {
            throw new InvalidArgumentException('admin_settings_query_invalid');
        }

        if ($this->config === null) {
            throw new RuntimeException('admin_settings_unavailable');
        }

        $groups = array_column($this->groups(), 'label', 'id');
        $terms = preg_split('/\s+/u', trim($query), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];

        foreach ($this->definitions() as [$key, $label, $category, $value]) {
            if ($group !== '' && $category !== $group) {
                continue;
            }

            $label = $this->translator->translate($label);
            $haystack = $key . ' ' . $label . ' ' . $category . ' ' . $groups[$category];
            $matched = true;

            foreach ($terms as $term) {
                if (
                    !str_contains(self::fold($haystack), self::fold($term))
                    && !str_contains(self::fold($haystack, true), self::fold($term, true))
                    && !str_contains(self::fold($haystack), self::fold($term, true))
                ) {
                    $matched = false;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            $route = match ($category) {
                'forum' => CoreRoutes::ADMIN_FORUMS,
                'registration' => CoreRoutes::ADMIN_REGISTRATION,
                'mail' => CoreRoutes::ADMIN_MAIL,
                default => null,
            };
            $type = match (ConfigSchema::KEYS[$key][0]) {
                ConfigSchema::BOOLEAN => 'admin.settings.type_boolean',
                ConfigSchema::INTEGER => 'admin.settings.type_integer',
                default => 'admin.settings.type_text',
            };
            $result[] = ['key' => $key, 'label' => $label, 'group' => $groups[$category],
                'value' => $this->display($key, $value), 'type' => $this->translator->translate($type),
                'url' => $route === null ? null : $this->urls->generate($route)];
        }

        return $result;
    }

    /** @return list<array{string, string, string, string|int|bool|null}> */
    private function definitions(): array
    {
        $c = $this->config ?? throw new RuntimeException('admin_settings_unavailable');

        return [
            ['locale.default', 'admin.settings.locale', 'locale', $c->defaultLocale],
            ['locale.time_zone', 'admin.settings.time_zone', 'locale', $c->defaultTimeZone],
            ['forums.max_depth', 'admin.settings.max_depth', 'forum', $c->forumMaxDepth],
            ['threads.per_page', 'admin.settings.per_page', 'forum', $c->threadsPerPage],
            ['posting.ip_interval_seconds', 'admin.settings.post_interval', 'posting', $c->postingIpInterval],
            ['posting.first_posts_moderated', 'admin.settings.first_posts', 'posting',
                $c->postingRules->firstPostsModerated],
            ['antispam.link_min_posts', 'admin.settings.link_posts', 'posting', $c->postingRules->linkMinPosts],
            ['antispam.link_min_age_hours', 'admin.settings.link_age', 'posting', $c->postingRules->linkMinAgeHours],
            ['antispam.new_user_max_links', 'admin.settings.max_links', 'posting', $c->postingRules->newUserMaxLinks],
            ['registration.mode', 'admin.settings.registration_mode', 'registration', $c->registration->mode],
            ['registration.challenge', 'admin.settings.challenge', 'registration', $c->registration->challenge],
            ['registration.min_seconds', 'admin.settings.fill_time', 'registration', $c->registration->minSeconds],
            ['registration.ip_interval_seconds', 'admin.settings.registration_interval', 'registration',
                $c->registration->networkSeconds],
            ['mail.transport', 'admin.settings.transport', 'mail', $c->mail->transport],
            ['mail.smtp_port', 'admin.settings.smtp_port', 'mail', $c->mail->smtpPort],
            ['mail.smtp_encryption', 'admin.settings.encryption', 'mail', $c->mail->smtpEncryption],
            ['http.require_https', 'admin.settings.https', 'security', $c->requireHttps],
            ['http.hsts', 'admin.settings.hsts', 'security', $c->hsts],
            ['privacy.ip_logging', 'admin.settings.ip_logging', 'security', $c->ipLogging->value],
        ];
    }

    private function display(string $key, string|int|bool|null $value): string
    {
        if ($value === null) {
            return $this->translator->translate('admin.settings.automatic');
        }

        if (is_bool($value)) {
            return $this->translator->translate($value ? 'admin.settings.enabled' : 'admin.settings.disabled');
        }

        $label = match ($key . ':' . $value) {
            'registration.mode:open' => 'admin.registration.mode_open',
            'registration.mode:verify' => 'admin.registration.mode_verify',
            'registration.mode:approval' => 'admin.registration.mode_approval',
            'registration.mode:closed' => 'admin.registration.mode_closed',
            'registration.challenge:none' => 'admin.registration.challenge_none',
            'registration.challenge:question' => 'admin.registration.challenge_question',
            'mail.transport:mail' => 'admin.settings.native_mail',
            'mail.transport:smtp' => 'admin.settings.smtp',
            'mail.smtp_encryption:starttls' => 'installer.mail.starttls',
            'mail.smtp_encryption:smtps' => 'installer.mail.smtps',
            'privacy.ip_logging:full' => 'admin.settings.ip_full',
            'privacy.ip_logging:truncated' => 'admin.settings.ip_truncated',
            'privacy.ip_logging:none' => 'admin.settings.disabled',
            default => null,
        };

        return $label === null ? (string) $value : $this->translator->translate($label);
    }

    private static function fold(string $text, bool $turkish = false): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;

        if ($turkish) {
            $text = strtr($text, ['I' => 'ı', 'İ' => 'i']);
        }

        return str_replace("i\u{0307}", 'i', mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'));
    }
}
