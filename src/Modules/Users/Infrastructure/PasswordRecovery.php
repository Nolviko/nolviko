<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\RateLimit\ClientNetwork;
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\IdentityRules;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\ResetTokens;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use SensitiveParameter;

/**
 * Password reset (M02.05). A request is answered the same way for every address; only an active account gets
 * a mail, at most one per account every 5 minutes (silent), and each client network may ask once a minute
 * (reported). Resetting checks the password policy, then sets the hash, uses up every reset token of the user,
 * deletes the remember-me tokens and raises the session epoch, which logs out every session.
 */
final class PasswordRecovery
{
    public const NETWORK_SECONDS = 60;

    public const ACCOUNT_SECONDS = 300;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly ResetTokens $tokens,
        private readonly AccountMail $mail,
        private readonly RateBuckets $buckets,
        ?Closure $clock = null,
        private readonly ?ResetMailQueue $queue = null,
        private readonly string $locale = 'en',
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @return int 0, or the seconds this client network has to wait before asking again.
     *
     * @throws MailDeliveryFailed
     */
    public function request(string $email, ?string $clientIp): int
    {
        $now = ($this->clock)();
        $network = ClientNetwork::key($clientIp);
        $networkKey = 'reset.net:' . ($network ?? 'unknown');
        $this->buckets->ensure($networkKey, $now);
        $wait = $this->buckets->hit($networkKey, self::NETWORK_SECONDS, $now);

        if ($wait > 0) {
            return $wait;
        }

        $address = IdentityRules::email($email);
        $user = $address === null ? null : $this->users->findByEmail($address);

        $recipient = null;
        if ($user !== null && $user->status === UserStatus::Active) {
            $accountKey = 'reset.user:' . $user->id;
            $this->buckets->ensure($accountKey, $now);
            if ($this->buckets->hit($accountKey, self::ACCOUNT_SECONDS, $now) === 0) {
                $recipient = $user;
            }
        }

        if ($this->queue !== null) {
            $this->queue->enqueue($recipient, $network, $now, $this->locale);
        } elseif ($recipient !== null) {
            $this->mail->passwordReset($recipient->email, $recipient->username, $this->tokens->issue($recipient->id, $network, $now));
        }

        return 0;
    }

    /**
     * Whether the link can still be used (for showing the form; it is not used up).
     */
    public function isValid(#[SensitiveParameter] string $token): bool
    {
        return $this->tokens->peek($token, ($this->clock)()) !== null;
    }

    /**
     * @return bool False when the token is unknown, used or expired.
     *
     * @throws RegistrationRejected With `password_*` or `password_mismatch` (the token stays usable).
     */
    public function reset(
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $password,
        #[SensitiveParameter]
        string $confirmation,
    ): bool {
        $now = ($this->clock)();
        $userId = $this->tokens->peek($token, $now);
        $user = $userId === null ? null : $this->users->findById($userId);

        if ($user === null) {
            return false;
        }

        $diagnostics = [
            ...PasswordPolicy::check($password, [$user->username, $user->email]),
            ...($password !== $confirmation ? ['password_mismatch'] : []),
        ];

        if ($diagnostics !== []) {
            throw new RegistrationRejected($diagnostics);
        }

        return $this->tokens->reset($token, $this->hasher->hash($password), $now) !== null;
    }
}
