<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

/**
 * The Content-Security-Policy nonce of one request; inline `<script>`/`<style>` must carry it (templates, M05).
 */
final class CspNonce
{
    private const BYTES = 16;

    public function __construct(
        public readonly string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(base64_encode(random_bytes(self::BYTES)));
    }
}
