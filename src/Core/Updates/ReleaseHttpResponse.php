<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Bounded discovery response, without authentication headers or transport exception text.
 */
final readonly class ReleaseHttpResponse
{
    public const MAX_RETRY_HINT = 9999999999;

    public function __construct(
        public int $status,
        public string $body,
        public ?string $etag = null,
        public ?int $retryAfter = null,
        public ?int $rateLimitReset = null,
    ) {
        foreach ([$retryAfter, $rateLimitReset] as $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_RETRY_HINT)) {
                throw new RuntimeException('github_response_retry_invalid');
            }
        }
    }
}
