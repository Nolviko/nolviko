<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Extensions\AuthChallenge;
use Nolviko\Core\Extensions\SecondFactorProvider;
use Nolviko\Core\Routing\CoreRoutes;
use SensitiveParameter;

/** Accepts "code-N" for N above the stored counter, so every code works once (like a TOTP time step). */
final class CountingSecondFactor implements SecondFactorProvider
{
    public function label(): string
    {
        return 'auth.account.second_factor_heading';
    }

    public function setupRoute(): string
    {
        return CoreRoutes::ACCOUNT;
    }

    public function verify(
        AuthChallenge $challenge,
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $response,
    ): ?string {
        $n = preg_match('/^code-(\d+)$/D', $response, $m) === 1 ? (int) $m[1] : 0;

        return $n > (int) $state ? (string) $n : null;
    }
}
