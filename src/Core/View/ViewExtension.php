<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\LocaleDirection;
use Nolviko\Core\Locale\LocaleResolver;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Posts\Domain\WordCensor;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template policy, the `safe_url` filter and the globals `csp_nonce` (`<script nonce="{{ csp_nonce }}">`),
 * `html_lang` and `html_dir` (the request locale, read on first render, after the Kernel resolved it).
 */
final class ViewExtension extends AbstractExtension implements GlobalsInterface
{
    /** Returned instead of a URL whose scheme could run script (javascript:, data:, vbscript:, …). */
    public const BLOCKED_URL = '#';

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public function __construct(
        private readonly ?CspNonce $nonce,
        private readonly ?MessageTranslator $translator = null,
        private readonly ?AssetManifest $assets = null,
        private readonly ?Closure $assetBase = null,
        private readonly ?UrlGenerator $urls = null,
        private readonly ?Closure $censored = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('path', function (string $name, array $parameters = []): string {
            return $this->urls?->generate($name, $parameters) ?? '#';
        }), new TwigFunction('asset', function (string $name): string {
            $path = $this->assets?->path($name) ?? throw new TemplateException('asset_manifest_missing');
            $base = $this->assetBase === null ? '' : ($this->assetBase)();

            return rtrim($base, '/') . '/' . $path;
        })];
    }

    public function getNodeVisitors(): array
    {
        return [new TemplatePolicy()];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('safe_url', self::safeUrl(...)),
            new TwigFilter('json_ld', self::jsonLd(...)),
            new TwigFilter('plain', self::plain(...)),
            new TwigFilter('censor', $this->censor(...)),
        ];
    }

    /**
     * Structured data (schema.org JSON-LD, 2026-11-08) as a script body: `<`, `>`, `&` and quotes are escaped as
     * \u sequences, so no value can close the script element or start markup.
     *
     * @param array<string, mixed> $data
     */
    public static function jsonLd(array $data): SafeHtml
    {
        return new SafeHtml(json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** Thread titles and other plain text with the site's censored words masked (WordCensor). */
    public function censor(?string $text): string
    {
        $words = $this->censored === null ? [] : ($this->censored)();

        return is_array($words) && $words !== [] ? WordCensor::text((string) $text, $words) : (string) $text;
    }

    /**
     * Plain text of rendered HTML (tags removed, entities decoded, whitespace collapsed), cut to $length
     * characters with an ellipsis; for descriptions and structured data. Twig escapes the result as usual.
     */
    public static function plain(SafeHtml|string|null $html, int $length = 160): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        $locale = $this->translator?->locale() ?? 'en';

        return [
            'csp_nonce' => $this->nonce?->value ?? '',
            'html_lang' => LocaleResolver::htmlTag($locale),
            'html_dir' => LocaleDirection::of($locale),
        ];
    }

    /**
     * Keeps http(s)/mailto and same-site relative URLs; anything else becomes `#`. The result is still
     * HTML-escaped by autoescaping, so it is safe inside a quoted `href`/`src` attribute.
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return self::BLOCKED_URL;
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $url, $match) === 1) {
            return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true) ? $url : self::BLOCKED_URL;
        }

        return str_starts_with($url, '//') ? self::BLOCKED_URL : $url;
    }
}
