<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\RateLimit\ClientNetwork;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Users\Domain\AccessBans;
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\IdentityRules;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Domain\VerificationTokens;
use SensitiveParameter;

/**
 * Registration and email verification (M02.03). Every accepted form gets the same answer whether or not the
 * address has an account: a new account is created `pending_verification` (with its Registered membership in
 * the same transaction) and gets a verification mail; an existing account gets a notice mail instead; a banned
 * address or network gets nothing (M04.09a). The password is hashed on every path, so timing tells them apart
 * no better than the response does. Resending is limited per client network (reported) and per account
 * (silent, so it reveals nothing).
 *
 * Modes (M02.03a): `open` creates the account active without a verification mail, `verify` is the default
 * above, `approval` verifies the address and then leaves the account `pending_approval` for an administrator,
 * `closed` refuses every form. Accepted forms are limited per client network before any account is looked at.
 */
final class AccountRegistration
{
    /** Seconds between resend requests from one client network. */
    public const RESEND_NETWORK_SECONDS = 60;

    /** Seconds between verification or notice mails to one account. */
    public const ACCOUNT_MAIL_SECONDS = 300;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly UsergroupRepository $groups,
        private readonly PasswordHasher $hasher,
        private readonly VerificationTokens $tokens,
        private readonly AccountMail $mail,
        private readonly RateBuckets $buckets,
        private readonly ?AccessBans $bans = null,
        ?Closure $clock = null,
        private readonly RegistrationRules $rules = new RegistrationRules(),
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function mode(): string
    {
        return $this->rules->mode;
    }

    /**
     * @throws RegistrationRejected For form errors, and `registration_closed`.
     * @throws RegistrationThrottled When this client network registered too recently.
     * @throws MailDeliveryFailed When the verification or notice mail could not be sent.
     */
    public function register(
        string $username,
        string $email,
        #[SensitiveParameter]
        string $password,
        #[SensitiveParameter]
        string $confirmation,
        ?string $clientIp,
    ): void {
        if ($this->rules->mode === RegistrationRules::CLOSED) {
            throw new RegistrationRejected(['registration_closed']);
        }

        $name = IdentityRules::username($username);
        $address = IdentityRules::email($email);
        $diagnostics = [
            ...($name === null ? ['username_invalid'] : []),
            ...($address === null ? ['email_invalid'] : []),
            ...PasswordPolicy::check($password, [$username, $email]),
            ...($password !== $confirmation ? ['password_mismatch'] : []),
        ];

        if ($diagnostics !== [] || $name === null || $address === null) {
            throw new RegistrationRejected($diagnostics);
        }

        $now = ($this->clock)();

        if ($this->rules->networkSeconds > 0) {
            $network = 'register.net:' . (ClientNetwork::key($clientIp) ?? 'unknown');
            $this->buckets->ensure($network, $now);
            $wait = $this->buckets->hit($network, $this->rules->networkSeconds, $now);

            if ($wait > 0) {
                throw new RegistrationThrottled($wait);
            }
        }

        $hash = $this->hasher->hash($password);

        if ($this->bans?->blocks($clientIp, $address, $now) === true) {
            return;
        }

        $existing = $this->users->findByEmail($address);

        if ($existing !== null) {
            $this->notify($existing->id, $existing->email, $existing->username, $now);

            return;
        }

        $open = $this->rules->mode === RegistrationRules::OPEN;
        $status = $open ? UserStatus::Active : UserStatus::PendingVerification;

        try {
            $user = $this->connection->transactional(function () use ($name, $address, $hash, $status) {
                $user = $this->users->create($name, $address, $hash, $status);
                $this->groups->addMember($user->id, BuiltInGroup::Registered->value);

                return $user;
            });
        } catch (IdentityTaken $taken) {
            if ($taken->field !== IdentityTaken::EMAIL) {
                throw new RegistrationRejected(['identity_taken:' . $taken->field]);
            }

            // Registered by a parallel request a moment ago: treat it as an existing account.
            $raced = $this->users->findByEmail($address);

            if ($raced !== null) {
                $this->notify($raced->id, $raced->email, $raced->username, $now);
            }

            return;
        }

        if ($open) {
            return;
        }

        $this->buckets->ensure('verify.user:' . $user->id, $now);
        $this->buckets->hit('verify.user:' . $user->id, self::ACCOUNT_MAIL_SECONDS, $now);
        $token = $this->tokens->issue($user->id, $user->email, $now);
        $this->mail->verification($user->email, $user->username, $token);
    }

    /**
     * Sign-up with an outside account whose address the provider verified (2026-11-15). Same mode, name, ban and
     * network rules as the form, but no verification mail; in `approval` mode the account waits for approval.
     * The password is random and unknown: the member sets one with "Forgot password" when needed. An address
     * that has an account is refused, so an outside account never takes over an existing one.
     *
     * @throws RegistrationRejected `registration_closed`, `username_invalid`, `email_invalid`, `external_email_taken`,
     *     `external_banned`, `identity_taken:username`.
     * @throws RegistrationThrottled
     */
    public function registerExternal(string $username, string $email, ?string $clientIp): User
    {
        if ($this->rules->mode === RegistrationRules::CLOSED) {
            throw new RegistrationRejected(['registration_closed']);
        }

        $name = IdentityRules::username($username);
        $address = IdentityRules::email($email);
        if ($name === null || $address === null) {
            throw new RegistrationRejected($name === null ? ['username_invalid'] : ['email_invalid']);
        }

        $now = ($this->clock)();
        if ($this->bans?->blocks($clientIp, $address, $now) === true) {
            throw new RegistrationRejected(['external_banned']);
        }
        if ($this->users->findByEmail($address) !== null) {
            throw new RegistrationRejected(['external_email_taken']);
        }
        if ($this->rules->networkSeconds > 0) {
            $network = 'register.net:' . (ClientNetwork::key($clientIp) ?? 'unknown');
            $this->buckets->ensure($network, $now);
            $wait = $this->buckets->hit($network, $this->rules->networkSeconds, $now);
            if ($wait > 0) {
                throw new RegistrationThrottled($wait);
            }
        }

        $status = $this->rules->mode === RegistrationRules::APPROVAL ? UserStatus::PendingApproval : UserStatus::Active;
        $hash = $this->hasher->hash(bin2hex(random_bytes(32)));
        try {
            return $this->connection->transactional(function () use ($name, $address, $hash, $status): User {
                $user = $this->users->create($name, $address, $hash, $status);
                $this->groups->addMember($user->id, BuiltInGroup::Registered->value);

                return $user;
            });
        } catch (IdentityTaken $taken) {
            throw new RegistrationRejected([$taken->field === IdentityTaken::EMAIL
                ? 'external_email_taken' : 'identity_taken:' . $taken->field]);
        }
    }

    /**
     * A new verification mail for a pending account; any other address is answered the same way.
     *
     * @return int 0, or the seconds this client network has to wait before asking again.
     *
     * @throws MailDeliveryFailed
     */
    public function resend(string $email, ?string $clientIp): int
    {
        $now = ($this->clock)();
        $network = 'verify.resend.net:' . (ClientNetwork::key($clientIp) ?? 'unknown');
        $this->buckets->ensure($network, $now);
        $wait = $this->buckets->hit($network, self::RESEND_NETWORK_SECONDS, $now);

        if ($wait > 0) {
            return $wait;
        }

        $address = IdentityRules::email($email);
        $user = $address === null ? null : $this->users->findByEmail($address);

        if ($user === null || $user->status !== UserStatus::PendingVerification) {
            return 0;
        }

        $key = 'verify.user:' . $user->id;
        $this->buckets->ensure($key, $now);

        if ($this->buckets->hit($key, self::ACCOUNT_MAIL_SECONDS, $now) === 0) {
            $token = $this->tokens->issue($user->id, $user->email, $now);
            $this->mail->verification($user->email, $user->username, $token);
        }

        return 0;
    }

    /**
     * @return int|null The verified user's ID, or null for an unknown, used, expired or outdated token.
     */
    public function verify(string $token): ?int
    {
        $verified = $this->rules->mode === RegistrationRules::APPROVAL
            ? UserStatus::PendingApproval
            : UserStatus::Active;

        return $this->tokens->consume($token, ($this->clock)(), $verified);
    }

    private function notify(int $userId, string $email, string $username, DateTimeImmutable $now): void
    {
        $key = 'register.notice:' . $userId;
        $this->buckets->ensure($key, $now);

        if ($this->buckets->hit($key, self::ACCOUNT_MAIL_SECONDS, $now) === 0) {
            $this->mail->alreadyRegistered($email, $username);
        }
    }
}
