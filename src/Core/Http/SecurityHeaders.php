<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The application's response security policy, applied once to every response (HTML, JSON, redirects, errors).
 *
 * Scripts and styles run only from this origin or with the request's nonce: no `unsafe-inline`, no
 * `unsafe-eval`. HSTS is sent only when the request is HTTPS (as seen through trusted proxies) and enabled.
 */
final class SecurityHeaders
{
    /** Hosts of the click-to-load video players (Editor MediaEmbed, 2026-11-08). */
    public const FRAME_SOURCES = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];

    public const HSTS_MAX_AGE = 31536000;

    private const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), '
        . 'magnetometer=(), microphone=(), payment=(), usb=()';

    public function __construct(
        private readonly CspNonce $nonce,
        private readonly bool $hsts = false,
    ) {
    }

    public function contentSecurityPolicy(): string
    {
        $nonce = "'nonce-" . $this->nonce->value . "'";
        // Hosts enabled extensions added (API 1.4.0), e.g. Google Analytics; core's own sources come first.
        $extra = fn(string $directive): string => implode('', array_map(
            static fn(string $source): string => ' ' . $source,
            $this->nonce->sources($directive),
        ));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' " . $nonce . $extra('script-src'),
            // The hash admits only HtmlPage's fixed system-page style block (errors work without assets or Twig).
            "style-src 'self' " . $nonce . ' ' . HtmlPage::styleSource() . $extra('style-src'),
            "img-src 'self' data:" . $extra('img-src'),
            "font-src 'self'" . $extra('font-src'),
            "connect-src 'self'" . $extra('connect-src'),
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            // Click-to-load video players (Editor MediaEmbed) and extension hosts; nothing else may be framed.
            'frame-src ' . implode(' ', self::FRAME_SOURCES) . $extra('frame-src'),
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * @param Request|null $request Null before a request exists (early 503/500): no HSTS then.
     */
    public function apply(Response $response, ?Request $request): Response
    {
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $headers->set('X-Content-Type-Options', 'nosniff');
        // A page may ask for no referrer at all (password reset links, M02.05); everything else gets the default.
        if ($headers->get('Referrer-Policy') !== 'no-referrer') {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($this->hsts && $request?->isSecure() === true) {
            $headers->set('Strict-Transport-Security', 'max-age=' . self::HSTS_MAX_AGE);
        }

        return $response;
    }
}
