<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\RememberTokens;
use SensitiveParameter;

/**
 * RememberTokens on DBAL. Selector: 18 random bytes (24 base64url characters); validator: 32 random bytes.
 */
final class DbalRememberTokens implements RememberTokens
{
    private const COOKIE_PATTERN = '/^([A-Za-z0-9_-]{24}):([A-Za-z0-9_-]{43})$/D';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly Connection $connection,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function issue(int $userId): string
    {
        $selector = self::random(18);
        $validator = self::random(32);
        $now = ($this->clock)();
        $this->connection->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => $now->modify('+' . self::LIFETIME_DAYS . ' days')->format(self::TIMESTAMP),
            'created_at' => $now->format(self::TIMESTAMP),
        ]);

        return $selector . ':' . $validator;
    }

    public function consume(#[SensitiveParameter] string $cookie): ?array
    {
        if (preg_match(self::COOKIE_PATTERN, $cookie, $parts) !== 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?',
            [$parts[1]],
        );

        if ($row === false) {
            return null;
        }

        $userId = (int) $row['user_id'];

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $parts[2]))) {
            $this->revokeAll($userId);

            return null;
        }

        // Deleting by ID first means two requests racing with the same cookie cannot both rotate it.
        $deleted = $this->connection->delete('remember_tokens', ['id' => (int) $row['id']]);
        $expired = (string) $row['expires_at'] <= ($this->clock)()->format(self::TIMESTAMP);

        if ($deleted === 0 || $expired) {
            return null;
        }

        return [$userId, $this->issue($userId)];
    }

    public function revoke(#[SensitiveParameter] string $cookie): void
    {
        if (preg_match(self::COOKIE_PATTERN, $cookie, $parts) === 1) {
            $this->connection->delete('remember_tokens', ['selector' => $parts[1]]);
        }
    }

    public function revokeAll(int $userId): void
    {
        $this->connection->delete('remember_tokens', ['user_id' => $userId]);
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
