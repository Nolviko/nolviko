<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * Outcome of Authenticator::attempt(). `diagnostic` is the message ID shown to the visitor.
 */
final class LoginResult
{
    /** Unknown account or wrong password (one message for both). */
    public const INVALID = 'login_invalid';

    private function __construct(
        public readonly ?User $user,
        public readonly string $diagnostic,
    ) {
    }

    public static function success(User $user): self
    {
        return new self($user, '');
    }

    public static function invalid(): self
    {
        return new self(null, self::INVALID);
    }

    public static function inactive(User $user): self
    {
        return new self(null, 'login_account_' . $user->status->value);
    }

    public function succeeded(): bool
    {
        return $this->user !== null;
    }
}
