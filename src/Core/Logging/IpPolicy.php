<?php

declare(strict_types=1);

namespace Nolviko\Core\Logging;

/**
 * How client IP addresses are written to logs (config `privacy.ip_logging`, default truncated).
 */
enum IpPolicy: string
{
    case Full = 'full';

    /** IPv4 keeps the first 24 bits (`203.0.113.0`), IPv6 the first 48 (`2001:db8:1::`). */
    case Truncated = 'truncated';

    case None = 'none';

    private const IPV4_PREFIX_BYTES = 3;

    private const IPV6_PREFIX_BYTES = 6;

    /**
     * @return string|null The value to log, or null to leave the address out.
     */
    public function apply(?string $address): ?string
    {
        if ($this === self::None || $address === null || $address === '') {
            return null;
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return 'invalid';
        }

        if ($this === self::Full) {
            return (string) inet_ntop($packed);
        }

        $keep = strlen($packed) === 4 ? self::IPV4_PREFIX_BYTES : self::IPV6_PREFIX_BYTES;

        return (string) inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));
    }
}
