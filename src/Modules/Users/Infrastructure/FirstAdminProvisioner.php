<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Users\Domain\FirstAdminRejected;
use Nolviko\Modules\Users\Domain\IdentityRules;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use SensitiveParameter;

/**
 * Creates the first administrator during installation (M02.15a; the deferred part of M01.14). One transaction:
 * the active account (password policy of M02.02), its Registered and primary Administrators memberships and the
 * audit entry. The Administrators group row is locked first, so two installer requests cannot both create an
 * administrator; once one exists, provisioning refuses (`installer_admin_exists`) and the installer only needs
 * to finish. A failure part-way rolls everything back and the step can be repeated.
 */
final class FirstAdminProvisioner
{
    public const AUDIT_ACTION = 'install.first_admin';

    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly UsergroupRepository $groups,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /** Existing administrator address also covers a retry after account creation but before install.lock. */
    public function administratorEmail(): ?string
    {
        $email = $this->connection->fetchOne(
            'SELECT u.email FROM users u INNER JOIN usergroup_members m ON m.user_id = u.id '
                . 'WHERE m.usergroup_id = ? ORDER BY u.id LIMIT 1',
            [BuiltInGroup::Administrators->value],
        );

        return is_string($email) ? $email : null;
    }

    public function hasAdministrator(): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM usergroup_members WHERE usergroup_id = ? LIMIT 1',
            [BuiltInGroup::Administrators->value],
        ) !== false;
    }

    /**
     * @return int The new administrator's user ID.
     *
     * @throws FirstAdminRejected With `username_invalid`, `email_invalid`, `password_*`, `password_mismatch`,
     *     `identity_taken:username|email` or `installer_admin_exists`.
     */
    public function provision(
        string $username,
        string $email,
        #[SensitiveParameter]
        string $password,
        #[SensitiveParameter]
        string $confirmation,
    ): int {
        $name = IdentityRules::username($username);
        $address = IdentityRules::email($email);
        $diagnostics = [
            ...($name === null ? ['username_invalid'] : []),
            ...($address === null ? ['email_invalid'] : []),
            ...PasswordPolicy::check($password, [$username, $email]),
            ...($password !== $confirmation ? ['password_mismatch'] : []),
        ];

        if ($diagnostics !== [] || $name === null || $address === null) {
            throw new FirstAdminRejected($diagnostics);
        }

        $hash = $this->hasher->hash($password);

        return $this->connection->transactional(function (Connection $connection) use ($name, $address, $hash): int {
            $connection->fetchOne(
                'SELECT id FROM usergroups WHERE id = ? FOR UPDATE',
                [BuiltInGroup::Administrators->value],
            );

            if ($this->hasAdministrator()) {
                throw new FirstAdminRejected(['installer_admin_exists']);
            }

            try {
                $user = $this->users->create($name, $address, $hash, UserStatus::Active);
            } catch (IdentityTaken $taken) {
                throw new FirstAdminRejected(['identity_taken:' . $taken->field]);
            }

            $this->groups->addMember($user->id, BuiltInGroup::Administrators->value, true);
            $this->groups->addMember($user->id, BuiltInGroup::Registered->value);
            $connection->insert('audit_logs', [
                'actor_id' => $user->id,
                'action' => self::AUDIT_ACTION,
                'target_type' => 'user',
                'target_id' => $user->id,
                'reason' => null,
                'details' => json_encode(['username' => $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);

            return $user->id;
        });
    }
}
