<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Http;

use Closure;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Infrastructure\IndexNow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Robots is advisory, never an authorization control. Sitemap reads always use a guest, not the caller's account. */
final readonly class SeoHandler implements RouteHandler
{
    public function __construct(
        private SiteUrl $site,
        private UrlGenerator $urls,
        private Closure $sitemaps,
        private string $appSecret = '',
        private array $robots = [],
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($parameters['_route'] === CoreRoutes::INDEXNOW_KEY) {
            // IndexNow verification (2026-11-08): the key derived from the app secret, nothing else.
            return new Response($this->appSecret === '' ? '' : IndexNow::key($this->appSecret), $this->appSecret === ''
                ? 404 : 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff']);
        }

        if ($parameters['_route'] === CoreRoutes::ROBOTS) {
            $body = "User-agent: *\n"; // i18n-ignore: crawler protocol.
            if (!$this->site->configured()) {
                $body .= "Disallow: /\n"; // i18n-ignore: crawler protocol.
            } else {
                foreach (
                    ['admin', 'api', 'install', 'account', 'conversations', 'community', 'search', 'login',
                        'logout',
                    'register', 'verify-email', 'password', 'appearance', 'update-recovery.php',
                        'worker.php',
                    'config/', 'storage/', 'src/', 'vendor/', 'views/', 'themes/', 'extensions/',
                        'docs/'] as $path
                ) {
                    $body .= 'Disallow: ' // i18n-ignore: crawler protocol.
                        . $this->site->basePath() . '/' . $path . "\n";
                }
                $body .= 'Sitemap: ' // i18n-ignore: crawler protocol.
                    . $this->site->absolute($this->urls->generate(CoreRoutes::SITEMAP_INDEX)) . "\n";
            }
            // Extension filters (2026-11-15) may replace the body; a failing one leaves it unchanged.
            foreach ($this->robots as $filter) {
                try {
                    $filtered = $filter($body);
                } catch (Throwable) {
                    continue;
                }
                if (is_string($filtered) && strlen($filtered) <= 65536) {
                    $body = $filtered;
                }
            }
            return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
        }
        try {
            $maps = ($this->sitemaps)();
            $xml = match ($parameters['_route']) {
                CoreRoutes::SITEMAP_INDEX => $maps->index(),
                CoreRoutes::SITEMAP_FORUMS => $maps->forums(),
                CoreRoutes::SITEMAP_THREADS => $maps->threads(
                    $parameters['generation'],
                    (int) $parameters['page']
                ),
            };
            $status = $xml === null ? ($parameters['_route'] === CoreRoutes::SITEMAP_INDEX ? 503 : 404) : 200;
        } catch (Throwable) {
            $xml = null;
            $status = 503;
        }
        return new Response($xml ?? '', $status, ['Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Retry-After'
                => '60']);
    }
}
