<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\User;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;

/**
 * UserRepository on DBAL with bound parameters only. Uniqueness is left to the database: an insert that
 * violates a UNIQUE constraint becomes IdentityTaken for that field (no check-then-insert race).
 */
final class DbalUserRepository implements UserRepository
{
    private const COLUMNS = 'id, username, email, password_hash, status, preferred_locale, created_at, updated_at, '
        . 'time_zone';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly Connection $connection,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function create(
        string $username,
        string $email,
        string $passwordHash,
        UserStatus $status,
        ?string $preferredLocale = null,
    ): User {
        $now = $this->now();

        try {
            $this->connection->insert('users', [
                'username' => $username,
                'username_normalized' => IdentityNormalizer::username($username),
                'email' => $email,
                'email_normalized' => IdentityNormalizer::email($email),
                'password_hash' => $passwordHash,
                'status' => $status->value,
                'preferred_locale' => $preferredLocale,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $violation) {
            throw new IdentityTaken(
                str_contains($violation->getMessage(), UsersTable::EMAIL_CONSTRAINT)
                    ? IdentityTaken::EMAIL
                    : IdentityTaken::USERNAME,
            );
        }

        $user = $this->findById((int) $this->connection->lastInsertId());

        return $user ?? throw new LogicException('user_insert_lost');
    }

    public function findById(int $id): ?User
    {
        return $this->findOne('id = ?', $id);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->findOne('username_normalized = ?', IdentityNormalizer::username($username));
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOne('email_normalized = ?', IdentityNormalizer::email($email));
    }

    public function updatePasswordHash(int $id, string $passwordHash): bool
    {
        return $this->update($id, ['password_hash' => $passwordHash]);
    }

    public function updateStatus(int $id, UserStatus $status): bool
    {
        return $this->update($id, ['status' => $status->value]);
    }

    /**
     * @param array<string, string> $values
     */
    private function update(int $id, array $values): bool
    {
        return $this->connection->update('users', $values + ['updated_at' => $this->now()], ['id' => $id]) > 0;
    }

    /**
     * @param string $condition A fixed condition with one placeholder (never built from input).
     */
    private function findOne(string $condition, int|string $value): ?User
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE ' . $condition,
            [$value],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): User
    {
        $utc = new DateTimeZone('UTC');

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['password_hash'],
            UserStatus::from((string) $row['status']),
            $row['preferred_locale'] === null ? null : (string) $row['preferred_locale'],
            new DateTimeImmutable((string) $row['created_at'], $utc),
            new DateTimeImmutable((string) $row['updated_at'], $utc),
            $row['time_zone'] === null ? null : (string) $row['time_zone'],
        );
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
