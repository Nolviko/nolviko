<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

/**
 * The Content-Security-Policy nonce of one request; inline `<script>`/`<style>` must carry it (templates, M05).
 *
 * Enabled extensions may add outside hosts to a few directives (extension API 1.4.0, 2026-11-26), for example
 * Google Analytics or a CAPTCHA provider. Only `https://` hosts (optionally `*.`-prefixed) are accepted; keywords
 * such as `'unsafe-inline'` or `*` alone are never added.
 */
final class CspNonce
{
    public const DIRECTIVES = ['script-src', 'style-src', 'img-src', 'connect-src', 'frame-src', 'font-src'];

    private const BYTES = 16;

    private const SOURCE = '#^https://(?:\*\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}'
        . '[a-z0-9])?)+(?::[0-9]{2,5})?(?:/[A-Za-z0-9._~/-]*)?$#D';

    /** @var array<string, list<string>> */
    private array $sources = [];

    public function __construct(
        public readonly string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(base64_encode(random_bytes(self::BYTES)));
    }

    public static function validSource(string $directive, string $source): bool
    {
        return in_array($directive, self::DIRECTIVES, true) && strlen($source) <= 200
            && preg_match(self::SOURCE, $source) === 1;
    }

    /** Adds an outside host to one directive; invalid input is ignored (the policy only ever gets stricter). */
    public function allow(string $directive, string $source): void
    {
        if (
            self::validSource($directive, $source) && !in_array($source, $this->sources[$directive] ?? [], true)
            && count($this->sources[$directive] ?? []) < 32
        ) {
            $this->sources[$directive][] = $source;
        }
    }

    /** @return list<string> */
    public function sources(string $directive): array
    {
        return $this->sources[$directive] ?? [];
    }
}
