<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DomainException;

/**
 * Too many registrations from one client network (M02.03a); the form may be sent again after `$seconds`.
 */
final class RegistrationThrottled extends DomainException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct('register_wait');
    }
}
