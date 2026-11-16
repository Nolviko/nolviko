<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use SensitiveParameter;

/**
 * Checks a login: username or email plus password.
 *
 * An unknown account and a wrong password give the same result, and an unknown account still verifies the
 * password against a dummy hash, so response time does not reveal which accounts exist. Only after the
 * password is right does the result say that the account cannot log in yet (unverified, pending, suspended).
 */
final class Authenticator
{
    /**
     * Precomputed with PasswordHasher's parameters (so verifying costs the same as for a real account, and no
     * hash has to be computed first). Keys are the PASSWORD_* constant values (Argon2id may not be compiled in).
     * Regenerate both when those parameters change.
     */
    private const DUMMY_HASHES = [
        'argon2id' => '$argon2id$v=19$m=19456,t=2,p=1$elpXbXZXQnhEcmtRQlRRaw$'
            . 'Xgz6ycmvKn7j3FvgJ5g+5ELrZn4URkrEf3wqY8Vnez8',
        '2y' => '$2y$11$XBHM.tTqrLKG7AzmuWE0ceH31vJDlIs9HfOubPHozCMHbMWYro2jW',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function attempt(string $identifier, #[SensitiveParameter] string $password): LoginResult
    {
        $identifier = trim($identifier);
        $user = $identifier === '' ? null : (
            str_contains($identifier, '@')
                ? $this->users->findByEmail($identifier)
                : $this->users->findByUsername($identifier)
        );

        if ($user === null) {
            $this->hasher->verify($password, self::DUMMY_HASHES[$this->hasher->algorithm()]);

            return LoginResult::invalid();
        }

        $matched = $this->hasher->verifyAndUpgrade(
            $password,
            $user->passwordHash,
            fn(string $hash): bool => $this->users->updatePasswordHash($user->id, $hash),
        );

        if (!$matched) {
            return LoginResult::invalid();
        }

        return $user->status === UserStatus::Active ? LoginResult::success($user) : LoginResult::inactive($user);
    }
}
