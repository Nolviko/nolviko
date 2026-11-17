<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\IdentityRules;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserRepository;
use SensitiveParameter;

/**
 * Changing one's own password or email address (M02.16). Both need the current password and are limited to one
 * attempt per account every few seconds, so the current password cannot be guessed through these forms.
 *
 * A new password ends every other session (session epoch) and deletes all remember-me tokens and open reset
 * links. A new address is only requested here: a link goes to the new address and a notice to the old one, and
 * the address changes when the link is used. An address that belongs to another account gets the same answer
 * and no mail, so the form tells nothing about other accounts.
 */
final class AccountSettings
{
    public const ATTEMPT_SECONDS = 5;

    public const LIFETIME = '+24 hours';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/D';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AccountMail $mail,
        private readonly RateBuckets $buckets,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @return User The account with its new hash, for logging the current session in again.
     *
     * @throws RegistrationRejected `current_password_invalid`, `password_*` or `password_mismatch`.
     * @throws RegistrationThrottled
     */
    public function changePassword(
        int $userId,
        #[SensitiveParameter]
        string $current,
        #[SensitiveParameter]
        string $password,
        #[SensitiveParameter]
        string $confirmation,
    ): User {
        $now = ($this->clock)();
        $user = $this->authorise($userId, $current, $now);
        $diagnostics = [
            ...PasswordPolicy::check($password, [$user->username, $user->email]),
            ...($password !== $confirmation ? ['password_mismatch'] : []),
        ];

        if ($diagnostics !== []) {
            throw new RegistrationRejected($diagnostics);
        }

        $hash = $this->hasher->hash($password);
        $stamp = self::stamp($now);
        $this->connection->transactional(static function (Connection $connection) use ($userId, $hash, $stamp): void {
            $connection->executeStatement(
                'UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1, updated_at = ? WHERE id = ?',
                [$hash, $stamp, $userId],
            );
            $connection->executeStatement('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            $connection->executeStatement(
                'UPDATE email_change_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [$stamp, $userId],
            );
            $connection->executeStatement(
                'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [$stamp, $userId],
            );
        });

        return $this->users->findById($userId) ?? throw new RegistrationRejected(['current_password_invalid']);
    }

    /**
     * @throws RegistrationRejected `current_password_invalid`, `email_invalid` or `email_unchanged`.
     * @throws RegistrationThrottled
     * @throws MailDeliveryFailed
     */
    public function requestEmailChange(int $userId, #[SensitiveParameter] string $current, string $email): void
    {
        $now = ($this->clock)();
        $user = $this->authorise($userId, $current, $now);
        $address = IdentityRules::email($email);

        if ($address === null) {
            throw new RegistrationRejected(['email_invalid']);
        }

        if (IdentityNormalizer::email($address) === IdentityNormalizer::email($user->email)) {
            throw new RegistrationRejected(['email_unchanged']);
        }

        if ($this->users->findByEmail($address) !== null) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('email_change_tokens', [
            'user_id' => $userId,
            'new_email' => $address,
            'token_hash' => hash('sha256', $token),
            'expires_at' => self::stamp($now->modify(self::LIFETIME)),
            'created_at' => self::stamp($now),
        ]);
        $this->mail->emailChangeConfirm($address, $user->username, $token);
        $this->mail->emailChangeNotice($user->email, $user->username);
    }

    /**
     * The member's own language and time zone (2026-11-17); null means "the site default". The caller validates
     * both against the installed locales and the IANA zone list.
     */
    public function changePreferences(int $userId, ?string $locale, ?string $timeZone): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET preferred_locale = ?, time_zone = ?, updated_at = ? WHERE id = ?',
            [$locale, $timeZone, self::stamp(($this->clock)()), $userId],
        );
    }

    public function isValidChange(#[SensitiveParameter] string $token): bool
    {
        return preg_match(self::TOKEN, $token) === 1 && $this->connection->fetchOne(
            'SELECT 1 FROM email_change_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), self::stamp(($this->clock)())],
        ) !== false;
    }

    /**
     * Uses the link and moves the account to the new address, if no other account took it meanwhile.
     *
     * @return int|null The user's ID, or null for an unknown, used, expired or no longer free address.
     */
    public function confirmEmailChange(#[SensitiveParameter] string $token): ?int
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }

        $stamp = self::stamp(($this->clock)());
        $owner = $this->connection->fetchOne(
            'SELECT user_id FROM email_change_tokens WHERE token_hash = ?',
            [hash('sha256', $token)],
        );

        if ($owner === false) {
            return null;
        }

        try {
            return $this->connection->transactional(static function (Connection $connection) use (
                $token,
                $stamp,
                $owner,
            ): ?int {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $owner]);
                $row = $connection->fetchAssociative(
                    'SELECT user_id, new_email, used_at, expires_at FROM email_change_tokens WHERE token_hash = ?'
                        . ' FOR UPDATE',
                    [hash('sha256', $token)],
                );

                if ($row === false || $row['used_at'] !== null || (string) $row['expires_at'] <= $stamp) {
                    return null;
                }

                $userId = (int) $row['user_id'];
                $connection->executeStatement(
                    'UPDATE email_change_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                    [$stamp, $userId],
                );
                $connection->executeStatement(
                    'UPDATE users SET email = ?, email_normalized = ?, updated_at = ? WHERE id = ?',
                    [$row['new_email'], IdentityNormalizer::email((string) $row['new_email']), $stamp, $userId],
                );

                return $userId;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @throws RegistrationRejected `current_password_invalid`.
     * @throws RegistrationThrottled
     */
    private function authorise(int $userId, #[SensitiveParameter] string $current, DateTimeImmutable $now): User
    {
        $key = 'account.change:' . $userId;
        $this->buckets->ensure($key, $now);
        $wait = $this->buckets->hit($key, self::ATTEMPT_SECONDS, $now);

        if ($wait > 0) {
            throw new RegistrationThrottled($wait);
        }

        $user = $this->users->findById($userId);

        if ($user === null || !$this->hasher->verify($current, $user->passwordHash)) {
            throw new RegistrationRejected(['current_password_invalid']);
        }

        return $user;
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
