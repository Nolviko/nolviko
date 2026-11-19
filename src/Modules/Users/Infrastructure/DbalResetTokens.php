<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\ResetTokens;
use SensitiveParameter;

/**
 * ResetTokens on DBAL. Lookups go through the unique hash index; `reset()` locks the token row, so a link
 * clicked twice changes the password once.
 */
final class DbalResetTokens implements ResetTokens
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(int $userId, ?string $network, DateTimeImmutable $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store($token, $userId, $network, $now);
        return $token;
    }

    public function store(
        #[SensitiveParameter]
        string $token,
        int $userId,
        ?string $network,
        DateTimeImmutable $now,
    ): void {
        $this->connection->insert('password_reset_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'requested_network' => $network === null ? null : hash('sha256', $network),
            'expires_at' => self::stamp($now->modify(self::LIFETIME)),
            'created_at' => self::stamp($now),
        ]);
    }

    public function peek(#[SensitiveParameter] string $token, DateTimeImmutable $now): ?int
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }

        $userId = $this->connection->fetchOne(
            'SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), self::stamp($now)],
        );

        return $userId === false ? null : (int) $userId;
    }

    public function reset(
        #[SensitiveParameter]
        string $token,
        string $passwordHash,
        DateTimeImmutable $now,
    ): ?int {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }

        $userId = $this->peek($token, $now);

        if ($userId === null) {
            return null;
        }

        return $this->connection->transactional(function (Connection $connection) use (
            $token,
            $passwordHash,
            $now,
            $userId,
        ): ?int {
            $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $row = $connection->fetchAssociative(
                'SELECT user_id, used_at, expires_at FROM password_reset_tokens WHERE token_hash = ? FOR UPDATE',
                [hash('sha256', $token)],
            );

            if ($row === false || $row['used_at'] !== null || (string) $row['expires_at'] <= self::stamp($now)) {
                return null;
            }

            $userId = (int) $row['user_id'];
            $stamp = self::stamp($now);
            $connection->executeStatement(
                'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [$stamp, $userId],
            );
            $connection->executeStatement(
                'UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1, updated_at = ? WHERE id = ?',
                [$passwordHash, $stamp, $userId],
            );
            $connection->executeStatement('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            $connection->executeStatement(
                'UPDATE email_change_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [$stamp, $userId],
            );

            return $userId;
        });
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
