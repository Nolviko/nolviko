<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Locale\LocaleFormatter;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TwigFormatExtension;
use Nolviko\Core\Locale\TwigTranslationExtension;
use Nolviko\Core\Routing\ReleaseId;
use Nolviko\Core\Routing\UrlGenerator;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFunction;

/**
 * Twig for the deployed release (ADR-007): theme chain loader, HTML autoescaping, strict variables, the
 * TemplatePolicy (no `raw`, no `autoescape false`), SafeHtml as the only unescaped HTML, and the request's CSP
 * nonce as `csp_nonce`.
 *
 * Compiled templates live in `<storage>/cache/twig/<release>/`, so a new release never loads classes compiled
 * from older sources and never needs to delete them first. A development checkout (no release file) uses
 * `dev/` with auto-reload; a release does not stat sources. Without a writable storage nothing is cached.
 */
final class TwigFactory
{
    public const CACHE_SUBDIRECTORY = 'cache/twig';

    public const DEVELOPMENT_CACHE = 'dev';

    public static function create(
        string $root,
        string $storage,
        MessageTranslator $translator,
        ?CspNonce $nonce = null,
        string $activeTheme = ThemeChain::DEFAULT_THEME,
        ?Closure $formatter = null,
        ?Closure $assetBase = null,
        ?string $themesDirectory = null,
        ?string $coreViews = null,
        ?string $cacheIdentity = null,
        ?array $themeSelection = null,
        ?ThemeSlots $slots = null,
        ?Cache $renderCache = null,
        ?Closure $renderIdentity = null,
        string $appearanceUrl = '/appearance',
        string $forumUrl = '/forums',
        ?UrlGenerator $urls = null,
        ?SeoMetadata $seo = null,
        ?Closure $viewer = null,
        ?Closure $site = null,
        ?Closure $censored = null,
    ): TwigRenderer {
        $releaseId = ReleaseId::fromFile($root . '/config/release.php');
        $chain = ThemeChain::resolve($themesDirectory ?? $root . '/themes', $activeTheme);
        $twig = new Environment(new ThemeLoader($chain, $coreViews ?? $root . '/views'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'auto_reload' => $releaseId === null,
            'cache' => self::usableCache($storage, $cacheIdentity === null ? $releaseId
                : ($releaseId ?? self::DEVELOPMENT_CACHE) . '-' . hash('sha256', $cacheIdentity)),
        ]);
        $twig->addExtension(new TwigTranslationExtension($translator));
        $twig->addExtension(new ViewExtension(
            $nonce,
            $translator,
            new AssetManifest($root),
            $assetBase,
            $urls,
            $censored,
        ));
        $twig->addExtension(new TwigFormatExtension(
            $formatter ?? static fn(): LocaleFormatter => new LocaleFormatter($translator->locale()),
        ));
        $twig->getRuntime(EscaperRuntime::class)->addSafeClass(SafeHtml::class, ['html']);
        // Search-engine metadata after extension filters (2026-11-15); core defaults without a SeoMetadata.
        // The title arrives as the rendered (escaped) title block and is decoded once; Twig escapes it again.
        $twig->addFunction(new TwigFunction(
            'seo_meta',
            static function (string $title, string $description, ?string $image = null) use ($seo): array {
                $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $seo?->meta($title, $description, $image) ?? ['title' => $title,
                    'description' => $description, 'keywords' => '', 'image' => $image,
                    'canonical' => null, 'noindex' => false];
            },
        ));

        // The installed Nolviko version for the admin footer (2026-11-17), from the release's own
        // config/release-compatibility.json; null in a development checkout.
        $twig->addFunction(new TwigFunction('nolviko_version', static function () use ($root): ?string {
            static $version = false;
            if ($version === false) {
                $file = $root . '/config/release-compatibility.json';
                $data = is_file($file) && !is_link($file)
                    ? json_decode((string) file_get_contents($file), true, 4) : null;
                $version = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
            }

            return $version;
        }));

        $slots ??= new ThemeSlots();
        $renderer = new TwigRenderer($twig, $renderCache, $renderIdentity, $nonce, $slots, $seo);
        $twig->addExtension(new ThemeViewExtension($slots, $themeSelection ?? [
            'css' => ThemeTokens::css([]), 'assets' => [], 'appearance' => 'system',
            'hash' => $cacheIdentity ?? 'default', 'preview' => false,
        ], $nonce, $appearanceUrl, $renderer->fragment(...), $forumUrl, $viewer, $site));

        return $renderer;
    }

    public static function cacheDirectory(string $storage, ?string $releaseId): string
    {
        return $storage . '/' . self::CACHE_SUBDIRECTORY . '/' . ($releaseId ?? self::DEVELOPMENT_CACHE);
    }

    /**
     * The cache directory if it exists or can be created and is writable, else false (compile per request):
     * pages, above all the installer that reports an unwritable storage, must render anyway.
     */
    private static function usableCache(string $storage, ?string $releaseId): string|false
    {
        $directory = self::cacheDirectory($storage, $releaseId);

        if (!is_dir($directory) && is_dir($storage)) {
            @mkdir($directory, 0700, true);
        }

        return is_dir($directory) && is_writable($directory) ? $directory : false;
    }
}
