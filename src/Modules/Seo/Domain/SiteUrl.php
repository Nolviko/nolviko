<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Domain;

/** Canonical origins come only from owner configuration, never request Host/forwarded headers or a query URL. */
final readonly class SiteUrl
{
    private ?string $origin;
    private string $base;

    public function __construct(?string $configured)
    {
        $parts = is_string($configured) && strlen($configured) <= 2048 ? parse_url($configured) : false;
        $valid = is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && is_string($parts['host'] ?? null) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('/[\x00-\x20\x7f\\\\]/', $configured) !== 1
            && filter_var($configured, FILTER_VALIDATE_URL) !== false;
        $base = $valid ? rtrim($parts['path'] ?? '', '/') : '';
        if (
            $base !== '' && (!str_starts_with($base, '/')
            || preg_match('~(?:^|/)\.\.?(?:/|$)|%2f|%5c|%0[ad]~i', $base) === 1)
        ) {
            $valid = false;
        }
        $this->origin = $valid ? strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '') : null;
        $this->base = $valid ? $base : '';
    }

    public function configured(): bool
    {
        return $this->origin !== null;
    }

    public function absolute(string $path): ?string
    {
        if (
            $this->origin === null || strlen($path) > 4096 || !str_starts_with($path, '/')
            || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $path) === 1
        ) {
            return null;
        }
        $prefixed = $this->base === '' || $path === $this->base || str_starts_with($path, $this->base . '/')
            || str_starts_with($path, $this->base . '?');
        return $this->origin . ($prefixed ? '' : $this->base) . $path;
    }

    /** `scheme://host[:port]` of the configured address (paths from the router already carry the base path). */
    public function origin(): ?string
    {
        return $this->origin;
    }

    public function basePath(): string
    {
        return $this->base;
    }

    public function identity(): string
    {
        return hash('sha256', ($this->origin ?? '') . $this->base);
    }
}
