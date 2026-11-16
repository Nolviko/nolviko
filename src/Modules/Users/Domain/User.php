<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;

/**
 * A stored account. Immutable; changes go through UserRepository and return a new instance.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly UserStatus $status,
        public readonly ?string $preferredLocale,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?string $timeZone = null,
    ) {
    }
}
