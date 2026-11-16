<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Login brute-force throttling (SECURITY.md "Authentication abuse").
 *
 * Per account (the normalized identifier as typed, so unknown names are throttled exactly like real ones and
 * nothing reveals which exist): from ACCOUNT_LIMIT failures within WINDOW_SECONDS, further attempts must wait a
 * cooldown that doubles from 30 s up to 5 minutes after the last failure. That is a delay, never a lock: the
 * owner can always log in again after at most 5 minutes. Per client network (IPv4 address, IPv6 /64): from
 * IP_LIMIT failures within the window, the network is blocked until the window has passed. A successful login
 * clears the account's failures. Old rows are pruned on about one write in a hundred (no cron needed).
 */
final class LoginThrottle
{
    public const ACCOUNT_LIMIT = 5;

    public const IP_LIMIT = 20;

    public const WINDOW_SECONDS = 900;

    private const FIRST_COOLDOWN = 30;

    private const MAX_COOLDOWN = 300;

    private const PRUNE_ONE_IN = 100;

    private readonly Closure $clock;

    private readonly Closure $random;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     * @param (Closure(): int)|null $random Returns 1..PRUNE_ONE_IN; null means random_int().
     */
    public function __construct(
        private readonly LoginAttempts $attempts,
        ?Closure $clock = null,
        ?Closure $random = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
        $this->random = $random ?? static fn(): int => random_int(1, self::PRUNE_ONE_IN);
    }

    /**
     * @return int Seconds the client must wait before this attempt is accepted; 0 means go ahead.
     */
    public function retryAfter(string $identifier, ?string $clientIp): int
    {
        $now = ($this->clock)();
        $since = $now->modify('-' . self::WINDOW_SECONDS . ' seconds');
        $network = self::network($clientIp);
        $wait = 0;

        if ($network !== null) {
            $ipFailures = $this->attempts->failuresForNetwork($network, $since);

            if (count($ipFailures) >= self::IP_LIMIT) {
                $oldestCounted = $ipFailures[count($ipFailures) - self::IP_LIMIT];
                $wait = self::secondsUntil($oldestCounted, self::WINDOW_SECONDS, $now);
            }
        }

        $accountFailures = $this->attempts->failuresForAccount(self::account($identifier), $since);
        $excess = count($accountFailures) - self::ACCOUNT_LIMIT;

        if ($excess >= 0) {
            $cooldown = min(self::FIRST_COOLDOWN * 2 ** min($excess, 10), self::MAX_COOLDOWN);
            $wait = max($wait, self::secondsUntil(end($accountFailures), $cooldown, $now));
        }

        return $wait;
    }

    public function record(string $identifier, ?string $clientIp, bool $succeeded, ?int $userId = null): void
    {
        $account = self::account($identifier);
        $now = ($this->clock)();

        if ($succeeded) {
            $this->attempts->clearAccount($account);
        }

        $this->attempts->record($account, self::network($clientIp) ?? '', $succeeded, $userId, $now);

        if (($this->random)() === 1) {
            $this->attempts->prune($now->modify('-' . self::WINDOW_SECONDS . ' seconds'));
        }
    }

    /**
     * The normalized identifier: email rules when it contains "@", username rules otherwise.
     */
    public static function account(string $identifier): string
    {
        return str_contains($identifier, '@')
            ? IdentityNormalizer::email($identifier)
            : IdentityNormalizer::username($identifier);
    }

    /**
     * Binary network key: the 4-byte IPv4 address, or the first 8 bytes of an IPv6 address (its /64) followed by
     * zeros; IPv4-mapped IPv6 counts as IPv4. Null for an unparsable address.
     */
    public static function network(?string $clientIp): ?string
    {
        $packed = $clientIp === null ? false : @inet_pton($clientIp);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return strlen($packed) === 16 ? substr($packed, 0, 8) . str_repeat("\0", 8) : $packed;
    }

    private static function secondsUntil(DateTimeImmutable $from, int $seconds, DateTimeImmutable $now): int
    {
        return max(0, $from->getTimestamp() + $seconds - $now->getTimestamp());
    }
}
