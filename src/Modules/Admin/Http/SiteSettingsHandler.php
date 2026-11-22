<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use DateTimeZone;
use InvalidArgumentException;
use Locale;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\PostingRules;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\StoredMailSettings;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/admin/site` (ADR-034): the administrator-editable settings. A key that `config/local.php` sets is shown
 * read-only with its effective value; secrets and infrastructure settings are never offered here.
 */
final readonly class SiteSettingsHandler implements RouteHandler
{
    public function __construct(
        private SiteSettings $settings,
        private ?Config $config,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        /** @var list<string> Installed catalog locales, offered as the default language. */
        private array $locales = ['en'],
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $input = $request->request->all();
        $flash = '_site_settings_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $pinned = $this->pinned();
        $error = null;
        $status = 200;
        $submitted = is_array($input['setting'] ?? null) ? array_filter(
            $input['setting'],
            static fn(mixed $value, mixed $key): bool => is_string($value) && is_string($key),
            ARRAY_FILTER_USE_BOTH,
        ) : [];

        if ($request->isMethod('POST')) {
            try {
                // Only installed languages can be the default; a forged value is refused like any invalid one.
                $locale = $submitted['locale.default'] ?? '';

                if ($locale !== '' && !in_array($locale, $this->locales, true)) {
                    throw new InvalidArgumentException('setting_invalid:locale.default');
                }

                if (
                    !$this->csrf->isValid(is_string($input[CsrfTokens::FIELD] ?? null) ? $input[CsrfTokens::FIELD] : '')
                    || !$this->admin->isFresh() || $subject->userId === null || !$subject->allows('admin.access')
                ) {
                    [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
                } else {
                    $changed = $this->settings->save($submitted, array_keys($pinned), $subject->userId);
                    $this->session->set($flash, $this->translator->translate(
                        $changed === 0 ? 'admin.groups.rules_unchanged' : 'admin.site.saved',
                    ));

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_SITE));
                }
            } catch (InvalidArgumentException $failure) {
                $key = substr($failure->getMessage(), strlen('setting_invalid:'));
                [$status, $error] = [422, $this->translator->translate('admin.site.invalid', [
                    'field' => $this->label($key),
                ])];
            } catch (Throwable) {
                [$status, $error] = [503, $this->translator->translate('admin.site.unavailable')];
            }
        }

        $stored = $this->settings->values();
        $fields = [];

        foreach (SiteSettings::KEYS as $key => [$type, $limit]) {
            // The SMTP block and the sender have their own page, Admin > Outgoing mail (ADR-034 amendment 2026-11-21).
            if (in_array($key, StoredMailSettings::SMTP_KEYS, true) || str_starts_with($key, 'mail.from_')) {
                continue;
            }

            $fields[] = [
                'key' => $key,
                'id' => 'setting-' . str_replace('.', '-', $key),
                'label' => $this->label($key),
                'hint' => $this->hint($key),
                'type' => in_array($type, ['locale', 'timezone'], true) ? 'enum' : $type,
                'value' => $status !== 200 && isset($submitted[$key]) ? $submitted[$key]
                    : ($pinned[$key] ?? $stored[$key] ?? ''),
                'options' => match ($type) {
                    'locale' => $this->localeOptions(),
                    'timezone' => $this->timeZoneOptions(),
                    default => [],
                } ?: ($type === 'enum' ? array_map(fn(string $option): array => ['value' => $option,
                    'label' => $this->translator->translate(match ($key) {
                        'registration.challenge' => 'admin.site.challenge',
                        'home.layout' => 'admin.site.layout',
                        'appearance.accent' => 'admin.site.accent',
                        'gallery.enabled', 'gallery.approval', 'api.enabled', 'seo.indexnow', 'posting.images',
                            'site.closed'
                            => 'admin.site.switch',
                        default => 'admin.site.mode',
                    }, ['mode' => $option])], $limit) : []),
                'min' => $type === 'int' ? $limit[0] : null, 'max' => $type === 'int' ? $limit[1] : $limit,
                'pinned' => isset($pinned[$key]),
                'default' => SiteSettings::DEFAULTS[$key] ?? '',
            ];
        }

        return new Response($this->views->render('admin/site.twig', [
            'title' => $this->translator->translate('admin.site.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_SITE),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_SITE), 'fields' => $fields,
            'error' => $error, 'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(), 'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * Keys that `config/local.php` sets (a value other than the built-in default), with their effective value.
     *
     * @return array<string, string>
     */
    private function pinned(): array
    {
        $pinned = [];
        $mail = $this->config?->mail;
        $rules = $this->config?->registration;

        if ($rules !== null && $rules->mode !== RegistrationRules::VERIFY) {
            $pinned['registration.mode'] = $rules->mode;
        }
        if ($rules !== null && $rules->minSeconds !== RegistrationRules::DEFAULT_MIN_SECONDS) {
            $pinned['registration.min_seconds'] = (string) $rules->minSeconds;
        }
        if ($rules !== null && $rules->challenge !== RegistrationRules::CHALLENGE_NONE) {
            $pinned['registration.challenge'] = $rules->challenge;
        }
        if ($this->config?->defaultLocale !== null) {
            $pinned['locale.default'] = $this->config->defaultLocale;
        }
        if ($this->config?->defaultTimeZone !== null) {
            $pinned['locale.time_zone'] = $this->config->defaultTimeZone;
        }
        if ($mail?->fromAddress !== null) {
            $pinned['mail.from_address'] = $mail->fromAddress;
        }
        if ($mail?->fromName !== null) {
            $pinned['mail.from_name'] = $mail->fromName;
        }

        $posting = $this->config?->postingRules;
        $default = new PostingRules();

        if ($posting !== null && $posting->blockedWords !== []) {
            $pinned['antispam.blocked_words'] = implode(', ', $posting->blockedWords);
        }
        if ($posting !== null && $posting->blockedDomains !== []) {
            $pinned['antispam.blocked_domains'] = implode(', ', $posting->blockedDomains);
        }
        if ($posting !== null && $posting->newUserMaxLinks !== $default->newUserMaxLinks) {
            $pinned['antispam.new_user_max_links'] = (string) $posting->newUserMaxLinks;
        }
        if ($posting !== null && $posting->linkMinPosts !== $default->linkMinPosts) {
            $pinned['antispam.link_min_posts'] = (string) $posting->linkMinPosts;
        }
        if ($posting !== null && $posting->firstPostsModerated !== $default->firstPostsModerated) {
            $pinned['antispam.first_posts_moderated'] = (string) $posting->firstPostsModerated;
        }

        return $pinned;
    }

    /**
     * "Automatic" first, then each installed language named in itself (English, Türkçe).
     *
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        $options = [['value' => '', 'label' => $this->translator->translate('admin.site.locale_auto')]];

        foreach ($this->locales as $locale) {
            $name = Locale::getDisplayName($locale, $locale);
            $options[] = ['value' => $locale, 'label' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1)];
        }

        return $options;
    }

    /**
     * "UTC (default)" first, then every time zone identifier.
     *
     * @return list<array{value: string, label: string}>
     */
    private function timeZoneOptions(): array
    {
        $options = [['value' => '', 'label' => $this->translator->translate('admin.site.time_zone_auto')]];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $options[] = ['value' => $zone, 'label' => str_replace('_', ' ', $zone)];
        }

        return $options;
    }

    private function label(string $key): string
    {
        $id = 'admin.site.field.' . str_replace('.', '_', $key);

        return $this->translator->has($id) ? $this->translator->translate($id) : $key;
    }

    private function hint(string $key): string
    {
        $id = 'admin.site.hint.' . str_replace('.', '_', $key);

        // The host's own upload limit caps every size setting (2026-11-14).
        return $this->translator->has($id) ? $this->translator->translate($id, ['server' => self::uploadLimit()]) : '';
    }

    /** The smaller of PHP's upload_max_filesize and post_max_size, as PHP writes it (for example 8M). */
    private static function uploadLimit(): string
    {
        $bytes = static function (string $value): int {
            $number = (int) $value;

            return match (strtoupper(substr(trim($value), -1))) {
                'G' => $number * 1073741824,
                'M' => $number * 1048576,
                'K' => $number * 1024,
                default => $number,
            };
        };
        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');

        return $bytes($post) > 0 && $bytes($post) < $bytes($upload) ? $post : $upload;
    }
}
