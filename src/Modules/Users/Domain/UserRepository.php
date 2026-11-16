<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * Account storage. Lookups take the raw input and normalize it (IdentityNormalizer) themselves.
 */
interface UserRepository
{
    /**
     * @throws IdentityTaken When the normalized username or email already exists.
     */
    public function create(
        string $username,
        string $email,
        string $passwordHash,
        UserStatus $status,
        ?string $preferredLocale = null,
    ): User;

    public function findById(int $id): ?User;

    public function findByUsername(string $username): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * Stores a new hash (password change, rehash on login). Returns false when the user does not exist.
     */
    public function updatePasswordHash(int $id, string $passwordHash): bool;

    public function updateStatus(int $id, UserStatus $status): bool;
}
