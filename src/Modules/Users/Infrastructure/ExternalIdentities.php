<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Extensions\ExternalIdentity;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Members' linked outside accounts (2026-11-15, `user_identities`). */
final readonly class ExternalIdentities
{
    public function __construct(private Connection $db)
    {
    }

    /** The member an outside account belongs to, or null. */
    public function userOf(ExternalIdentity $identity): ?int
    {
        $user = $this->db->fetchOne(
            'SELECT user_id FROM user_identities WHERE provider = ? AND subject = ?',
            [$identity->provider, $identity->subject],
        );

        return $user === false ? null : (int) $user;
    }

    /** @return array<string, string> Provider => stored address (or '') for one member. */
    public function forUser(int $userId): array
    {
        $rows = [];
        foreach (
            $this->db->fetchAllAssociative(
                'SELECT provider, email FROM user_identities WHERE user_id = ? ORDER BY provider',
                [$userId],
            ) as $row
        ) {
            $rows[(string) $row['provider']] = (string) ($row['email'] ?? '');
        }

        return $rows;
    }

    /**
     * @throws RegistrationRejected `external_taken` (the outside account belongs to another member) or
     *     `external_linked` (this member already has an account of that provider).
     */
    public function link(int $userId, ExternalIdentity $identity): void
    {
        $owner = $this->userOf($identity);
        if ($owner !== null) {
            throw new RegistrationRejected([$owner === $userId ? 'external_linked' : 'external_taken']);
        }

        try {
            $this->db->insert('user_identities', [
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'user_id' => $userId,
                'email' => $identity->verifiedEmail,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RegistrationRejected(['external_linked']);
        }
    }

    public function unlink(int $userId, string $provider): bool
    {
        return $this->db->delete('user_identities', ['user_id' => $userId, 'provider' => $provider]) > 0;
    }
}
