<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use DateTimeImmutable;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\RememberTokens;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;

/**
 * In-memory UserRepository and RememberTokens for handler tests (the DBAL ones are integration-tested).
 */
final class MemoryUsers implements UserRepository, RememberTokens
{
    /** @var array<int, User> */
    public array $users = [];

    /** @var array<string, array{0: int, 1: string}> Selector => user ID and validator. */
    public array $tokens = [];

    public function create(
        string $username,
        string $email,
        string $passwordHash,
        UserStatus $status,
        ?string $preferredLocale = null,
    ): User {
        if ($this->findByUsername($username) !== null) {
            throw new IdentityTaken(IdentityTaken::USERNAME);
        }

        if ($this->findByEmail($email) !== null) {
            throw new IdentityTaken(IdentityTaken::EMAIL);
        }

        $id = count($this->users) + 1;
        $now = new DateTimeImmutable('2026-10-31 12:00:00');

        $user = new User($id, $username, $email, $passwordHash, $status, $preferredLocale, $now, $now);

        return $this->users[$id] = $user;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->users as $user) {
            if (IdentityNormalizer::username($user->username) === IdentityNormalizer::username($username)) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if (IdentityNormalizer::email($user->email) === IdentityNormalizer::email($email)) {
                return $user;
            }
        }

        return null;
    }

    public function updatePasswordHash(int $id, string $passwordHash): bool
    {
        return $this->replace($id, passwordHash: $passwordHash);
    }

    public function updateStatus(int $id, UserStatus $status): bool
    {
        return $this->replace($id, status: $status);
    }

    public function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(16));
        $this->tokens[$selector] = [$userId, $validator];

        return $selector . ':' . $validator;
    }

    public function consume(string $cookie): ?array
    {
        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');
        $token = $this->tokens[$selector] ?? null;

        if ($token === null) {
            return null;
        }

        if ($token[1] !== $validator) {
            $this->revokeAll($token[0]);

            return null;
        }

        unset($this->tokens[$selector]);

        return [$token[0], $this->issue($token[0])];
    }

    public function revoke(string $cookie): void
    {
        unset($this->tokens[explode(':', $cookie, 2)[0]]);
    }

    public function revokeAll(int $userId): void
    {
        $this->tokens = array_filter($this->tokens, static fn(array $token): bool => $token[0] !== $userId);
    }

    private function replace(int $id, ?string $passwordHash = null, ?UserStatus $status = null): bool
    {
        $user = $this->users[$id] ?? null;

        if ($user === null) {
            return false;
        }

        $this->users[$id] = new User(
            $id,
            $user->username,
            $user->email,
            $passwordHash ?? $user->passwordHash,
            $status ?? $user->status,
            $user->preferredLocale,
            $user->createdAt,
            $user->updatedAt,
        );

        return true;
    }
}
