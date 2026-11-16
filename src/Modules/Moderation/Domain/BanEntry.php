<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * One IP or email ban in its stored form (M04.09a). Addresses are packed with `inet_pton` (4 bytes for IPv4,
 * 16 for IPv6) so a range is a byte-wise `start <= address <= end` comparison of equal length. Ranges are
 * written in CIDR form (`192.0.2.0/24`, `2001:db8::/32`); emails and domains are stored lowercased.
 */
final class BanEntry
{
    public const IP = 'ip';

    public const IP_RANGE = 'ip_range';

    public const EMAIL = 'email';

    public const EMAIL_DOMAIN = 'email_domain';

    public const TYPES = [self::IP, self::IP_RANGE, self::EMAIL, self::EMAIL_DOMAIN];

    private const MAX_LENGTH = 254;

    private const MAPPED = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    private function __construct(
        public readonly string $type,
        public readonly ?string $ipStart,
        public readonly ?string $ipEnd,
        public readonly ?string $emailPattern,
    ) {
    }

    /**
     * @throws ThreadRuleViolation `ban_type_invalid` or `ban_value_invalid`.
     */
    public static function parse(string $type, string $value): self
    {
        $value = strtolower(trim($value));

        return match ($type) {
            self::IP => self::address($value),
            self::IP_RANGE => self::range($value),
            self::EMAIL => self::email($value),
            self::EMAIL_DOMAIN => self::domain($value),
            default => throw new ThreadRuleViolation('ban_type_invalid'),
        };
    }

    /**
     * The packed form of a client address, or null when it is not an IP address. IPv4-mapped IPv6 addresses
     * (`::ffff:192.0.2.1`) are packed as the IPv4 address they carry.
     */
    public static function pack(?string $ip): ?string
    {
        $packed = $ip === null || $ip === '' ? false : @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        return strlen($packed) === 16 && str_starts_with($packed, self::MAPPED) ? substr($packed, 12) : $packed;
    }

    /**
     * The stored value in readable form: the email or domain, the address, or the first and last address of a
     * range.
     *
     * @return array{0: string, 1: ?string}
     */
    public function display(): array
    {
        if ($this->emailPattern !== null) {
            return [$this->emailPattern, null];
        }

        $start = (string) inet_ntop((string) $this->ipStart);

        return [$start, $this->ipStart === $this->ipEnd ? null : (string) inet_ntop((string) $this->ipEnd)];
    }

    public static function stored(string $type, ?string $ipStart, ?string $ipEnd, ?string $emailPattern): self
    {
        return new self($type, $ipStart, $ipEnd, $emailPattern);
    }

    private static function address(string $value): self
    {
        $packed = self::pack($value) ?? throw new ThreadRuleViolation('ban_value_invalid');

        return new self(self::IP, $packed, $packed, null);
    }

    private static function range(string $value): self
    {
        $parts = explode('/', $value);
        $packed = count($parts) === 2 ? self::pack($parts[0]) : null;

        if ($packed === null || preg_match('/^[0-9]{1,3}$/', $parts[1]) !== 1) {
            throw new ThreadRuleViolation('ban_value_invalid');
        }

        $bits = strlen($packed) * 8;
        $prefix = (int) $parts[1];

        if ($prefix < 1 || $prefix > $bits) {
            throw new ThreadRuleViolation('ban_value_invalid');
        }

        $start = '';
        $end = '';

        for ($byte = 0; $byte < strlen($packed); $byte++) {
            $keep = max(0, min(8, $prefix - $byte * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $start .= chr(ord($packed[$byte]) & $mask);
            $end .= chr((ord($packed[$byte]) & $mask) | (~$mask & 0xFF));
        }

        return new self(self::IP_RANGE, $start, $end, null);
    }

    private static function email(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ThreadRuleViolation('ban_value_invalid');
        }

        return new self(self::EMAIL, null, null, $value);
    }

    private static function domain(string $value): self
    {
        $value = ltrim($value, '@');

        $valid = strlen($value) <= self::MAX_LENGTH
            && str_contains($value, '.')
            && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (!$valid) {
            throw new ThreadRuleViolation('ban_value_invalid');
        }

        return new self(self::EMAIL_DOMAIN, null, null, $value);
    }
}
