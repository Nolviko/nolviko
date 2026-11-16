<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * The default (M02.03a): no question on the form; the honeypot and the minimum fill time still apply.
 */
final class NoChallenge implements RegistrationChallenge
{
    public function present(): ?array
    {
        return null;
    }

    public function passes(array $input): bool
    {
        return true;
    }
}
