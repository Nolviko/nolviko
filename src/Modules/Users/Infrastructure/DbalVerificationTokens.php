<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Domain\VerificationTokens;

/**
 * VerificationTokens on DBAL. Lookup is by the token's hash (the unique index), so comparing the secret takes
 * no branch on its bytes; consuming locks the token row, so two clicks cannot both succeed. Verifying marks
 * every open token of the user as used.
 */
final class DbalVerificationTokens implements VerificationTokens
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(int $userId, string $email, DateTimeImmutable $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('email_verification_tokens', [
            'user_id' => $userId,
            'email_normalized' => IdentityNormalizer::email($email),
            'token_hash' => hash('sha256', $token),
            'expires_at' => self::stamp($now->modify(self::LIFETIME)),
            'created_at' => self::stamp($now),
        ]);

        return $token;
    }

    public function consume(
        string $token,
        DateTimeImmutable $now,
        UserStatus $verified = UserStatus::Active,
    ): ?int {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }

        return $this->connection->transactional(function (Connection $connection) use ($token, $now, $verified): ?int {
            $row = $connection->fetchAssociative(
                'SELECT t.id, t.user_id, t.email_normalized, t.expires_at, t.used_at, u.email_normalized AS current,'
                    . ' u.status FROM email_verification_tokens t JOIN users u ON u.id = t.user_id'
                    . ' WHERE t.token_hash = ? FOR UPDATE',
                [hash('sha256', $token)],
            );

            if (
                $row === false
                || $row['used_at'] !== null
                || (string) $row['expires_at'] <= self::stamp($now)
                || $row['email_normalized'] !== $row['current']
                || $row['status'] !== UserStatus::PendingVerification->value
            ) {
                return null;
            }

            $userId = (int) $row['user_id'];
            $connection->executeStatement(
                'UPDATE email_verification_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [self::stamp($now), $userId],
            );
            $connection->executeStatement(
                'UPDATE users SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
                [$verified->value, self::stamp($now), $userId, UserStatus::PendingVerification->value],
            );

            return $userId;
        });
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
