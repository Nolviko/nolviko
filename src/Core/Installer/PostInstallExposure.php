<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Config\ConfigSchema;
use Symfony\Component\HttpFoundation\Request;

/** Explicit owner check using fixed local server origins and configured host; only small status values are cached. */
final class PostInstallExposure
{
    public function __construct(
        private readonly string $root,
        private readonly string $storage,
        private readonly ?string $siteUrl,
        private readonly Cache $cache,
    ) {
    }

    /** @return array<string, string> */
    public function status(): array
    {
        $cached = $this->cache->get('web-exposure', []);
        $result = [];

        foreach (['storage', 'config', 'vendor'] as $directory) {
            $value = is_array($cached) ? ($cached[$directory] ?? null) : null;
            $result[$directory] = in_array($value, [WebExposureProbe::EXPOSED, WebExposureProbe::PROTECTED], true)
                ? $value : WebExposureProbe::UNKNOWN;
        }

        return $result;
    }

    /** Called only from HTTPS + fresh-admin + CSRF checked POST. No arbitrary browser URL/Host is adopted. */
    public function check(Request $request): void
    {
        if ((int) $this->cache->get('web-exposure-at', 0) > time() - 60) {
            return;
        }

        $result = ['storage' => WebExposureProbe::UNKNOWN, 'config' => WebExposureProbe::UNKNOWN,
            'vendor' => WebExposureProbe::UNKNOWN];

        if ($this->siteUrl !== null && ConfigSchema::isBaseUrl($this->siteUrl)) {
            $parts = parse_url($this->siteUrl);
            $host = $parts['host'];

            if (isset($parts['port'])) {
                $host .= ':' . $parts['port'];
            }

            if (strtolower($request->getHttpHost()) === strtolower($host)) {
                $probeRequest = clone $request;
                $probeRequest->headers->set('Host', $host);
                $result = (new WebExposureProbe())->probe($probeRequest, $this->root, $this->storage);
            }
        }

        $this->cache->set('web-exposure', $result, 86400);
        $this->cache->set('web-exposure-at', time(), 86400);
    }
}
