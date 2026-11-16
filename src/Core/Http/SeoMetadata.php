<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Nolviko\Core\Extensions\PageMeta;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A per-request scalar projection, included in the Twig render-cache context as well as HTTP headers.
 *
 * Since 2026-11-15 extensions may filter the page's search-engine metadata (title, description, keywords, image,
 * canonical address, noindex) through `meta()`, which the page layout calls once with core's defaults.
 */
final class SeoMetadata
{
    /** Route => [page type, key prefix, key variable]. */
    private const PAGES = [
        CoreRoutes::HOME => ['home', 'home', null],
        CoreRoutes::FORUM_INDEX => ['home', 'home', null],
        CoreRoutes::FORUM_SHOW => ['forum', 'forum', 'forum'],
        CoreRoutes::THREAD_SHOW => ['thread', 'thread', 'thread'],
        CoreRoutes::THREAD_PAGE => ['thread', 'thread', 'thread'],
        CoreRoutes::POST_SHOW => ['other', null, null],
        CoreRoutes::TAG_SHOW => ['tag', 'tag', 'tag'],
        CoreRoutes::GALLERY => ['gallery', 'gallery', null],
        CoreRoutes::GALLERY_ALBUM => ['album', 'album', 'album'],
        CoreRoutes::GALLERY_ITEM => ['image', 'image', 'item'],
    ];

    /** Route variables the canonical address needs; the thread slug only when the route still has one. */
    private const KEYS = [
        CoreRoutes::HOME => [], CoreRoutes::FORUM_INDEX => [], CoreRoutes::FORUM_SHOW => ['forum'],
        CoreRoutes::THREAD_SHOW => ['thread'], CoreRoutes::THREAD_PAGE => ['thread', 'page'],
        CoreRoutes::POST_SHOW => ['post'], CoreRoutes::TAG_SHOW => ['tag'], CoreRoutes::GALLERY => [],
        CoreRoutes::GALLERY_ALBUM => ['album'], CoreRoutes::GALLERY_ITEM => ['item'],
    ];

    private array $metadata = ['canonical' => null, 'noindex' => true, 'origin' => null];

    private string $route = '';

    /** @var array<string, string> */
    private array $values = [];

    private bool $filteredNoindex = false;

    /** @param list<Closure(PageMeta): PageMeta> $filters */
    public function __construct(private readonly SiteUrl $site, private readonly array $filters = [])
    {
    }

    public function reset(): void
    {
        $this->metadata = ['canonical' => null, 'noindex' => true, 'origin' => $this->site->origin()];
        $this->route = '';
        $this->values = [];
        $this->filteredNoindex = false;
    }

    public function bind(array $parameters, UrlGenerator $urls, bool $authenticated): void
    {
        $this->reset();
        $route = $parameters['_route'] ?? '';
        $keys = self::KEYS[$route] ?? null;
        if ($keys === null || !$this->site->configured()) {
            return;
        }
        $values = [];
        foreach ([...$keys, 'slug'] as $key) {
            $value = $parameters[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $values[$key] = (string) $value;
            } elseif ($key !== 'slug') {
                return;
            }
        }
        $this->route = $route;
        $this->values = $values;
        $canonical = $this->site->absolute($urls->generate($route, $values));
        $this->metadata = ['canonical' => $canonical, 'noindex' => $authenticated || $canonical === null,
            'origin' => $this->site->origin()];
    }

    /** Extensions change the metadata, so cached page HTML would be stale after their settings change. */
    public function filtered(): bool
    {
        return $this->filters !== [];
    }

    public function view(): array
    {
        return $this->metadata;
    }

    /**
     * The metadata the page prints, after the extension filters (called once by the page layout).
     *
     * @return array{title: string, description: string, keywords: string, image: ?string, canonical: ?string,
     *     noindex: bool}
     */
    public function meta(string $title, string $description, ?string $image = null): array
    {
        [$type, $key] = self::page(['_route' => $this->route] + $this->values);
        $meta = new PageMeta(
            $type,
            $key,
            $title,
            $description,
            '',
            $image,
            $this->metadata['canonical'],
            false,
            isset($this->values['page']) ? ['page' => $this->values['page']] : [],
        );
        foreach ($this->filters as $filter) {
            try {
                $next = $filter($meta);
                $meta = $next instanceof PageMeta && $next->type === $meta->type && $next->key === $meta->key
                    ? $next : $meta;
            } catch (Throwable) {
                continue;
            }
        }

        $canonical = self::httpUrl($meta->canonical) ?? $this->metadata['canonical'];
        if ($canonical !== $this->metadata['canonical'] && $this->metadata['canonical'] !== null) {
            $this->metadata['canonical'] = $canonical;
        }
        $this->filteredNoindex = $meta->noindex;

        return [
            'title' => mb_substr(trim($meta->title) ?: $title, 0, 200),
            'description' => mb_substr(trim($meta->description), 0, 320),
            'keywords' => mb_substr(trim($meta->keywords), 0, 255),
            'image' => self::httpUrl($meta->image),
            'canonical' => $this->metadata['canonical'],
            'noindex' => $meta->noindex || $this->metadata['noindex'],
        ];
    }

    public function apply(Response $response): Response
    {
        if ($this->metadata['noindex'] || $this->filteredNoindex || $response->getStatusCode() !== 200) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            return $response;
        }
        if (
            $this->metadata['canonical'] !== null
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
        ) {
            $link = '<' . $this->metadata['canonical'] . '>; rel="canonical"';
            $existing = $response->headers->get('Link');
            $response->headers->set('Link', $existing === null ? $link : $existing . ', ' . $link);
        }
        return $response;
    }

    /**
     * Page type and key (PageMeta) for matched route attributes, e.g. ['thread', 'thread:12']; extensions use it
     * to find the page an administrator means by an address.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function page(array $parameters): array
    {
        [$type, $prefix, $variable] = self::PAGES[$parameters['_route'] ?? ''] ?? ['other', null, null];
        $value = $variable === null ? null : ($parameters[$variable] ?? null);

        return [$type, match (true) {
            $prefix === null => null,
            $variable === null => $prefix,
            is_string($value) || is_int($value) => $prefix . ':' . $value,
            default => null,
        }];
    }

    private static function httpUrl(?string $url): ?string
    {
        return $url !== null && strlen($url) <= 2000 && preg_match('~^https?://[^\s<>"\']+$~iD', $url) === 1
            ? $url : null;
    }
}
