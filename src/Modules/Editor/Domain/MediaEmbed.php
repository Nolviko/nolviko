<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * Video links that become click-to-load players (2026-11-08, owner decision: YouTube and Vimeo, nothing loads
 * before the reader clicks). A bare link alone on its line is rendered as a card with the ordinary link inside;
 * embed.js adds the play button and swaps in the player from the privacy-friendly host. Without JavaScript the
 * card stays a plain link, and no third-party request is ever made by the page itself. The player hosts are
 * SecurityHeaders::FRAME_SOURCES (`frame-src`).
 */
final class MediaEmbed
{
    /**
     * @return array{string, string}|null [provider, video ID] for a supported video address.
     */
    public static function match(string $url): ?array
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') || str_starts_with($host, 'm.')
            ? substr($host, (int) strpos($host, '.') + 1) : $host;
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $id = match ($host) {
            'youtube.com' => $path === '/watch' ? (is_string($query['v'] ?? null) ? $query['v'] : '')
                : (preg_match('~^/(?:shorts|embed|live)/([^/]+)$~', $path, $m) === 1 ? $m[1] : ''),
            'youtu.be' => ltrim($path, '/'),
            'vimeo.com' => ltrim($path, '/'),
            default => '',
        };

        if ($host === 'vimeo.com') {
            return preg_match('/^[1-9][0-9]{5,11}$/D', $id) === 1 ? ['vimeo', $id] : null;
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) === 1 ? ['youtube', $id] : null;
    }

    public static function html(string $provider, string $id): string
    {
        $canonical = $provider === 'vimeo' ? 'https://vimeo.com/' . $id : 'https://www.youtube.com/watch?v=' . $id;

        return '<div class="bb-embed" data-embed="' . $provider . '" data-embed-id="' . $id . '">'
            . '<a class="bb-embed-link" href="' . $canonical . '" rel="nofollow ugc noopener">' . $canonical . '</a>'
            . '</div>';
    }
}
