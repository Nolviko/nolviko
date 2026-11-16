<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DomainException;

/**
 * The username or email address is already used by another account (decided by the database's unique
 * constraint, so two simultaneous registrations cannot both win).
 */
final class IdentityTaken extends DomainException
{
    public const USERNAME = 'username';

    public const EMAIL = 'email';

    /**
     * @param string $field USERNAME or EMAIL.
     */
    public function __construct(public readonly string $field)
    {
        parent::__construct('identity_taken:' . $field);
    }
}
