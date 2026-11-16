<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DomainException;

/**
 * A registration form the rules refuse (M02.03): `username_invalid`, `email_invalid`, `password_*`,
 * `password_mismatch` or `identity_taken:username`. An address that is registered already is never one of them.
 */
final class RegistrationRejected extends DomainException
{
    /**
     * @param list<string> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(implode(',', $diagnostics));
    }
}
