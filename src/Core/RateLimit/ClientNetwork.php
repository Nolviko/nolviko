<?php

declare(strict_types=1);

namespace Nolviko\Core\RateLimit;

/**
 * The network a client address belongs to, as a pseudonymous key part for rate limits (M03.15): IPv4 /32 and
 * IPv6 /64 (one household or customer allocation), IPv4-mapped IPv6 treated as IPv4, then SHA-256, so no
 * address is stored. Null for a missing or malformed address (the limit is then skipped, not failed).
 */
final class ClientNetwork
{
    public static function key(?string $clientIp): ?string
    {
        $packed = $clientIp === null ? false : @inet_pton($clientIp);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        } elseif (strlen($packed) === 16) {
            $packed = substr($packed, 0, 8) . str_repeat("\0", 8);
        }

        return hash('sha256', $packed);
    }
}
