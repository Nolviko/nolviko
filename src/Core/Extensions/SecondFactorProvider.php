<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use SensitiveParameter;

/**
 * A second sign-in step offered by an extension (ADR-029; first used by the TOTP extension, 2026-11-15).
 *
 * Core owns everything around it: which members are enrolled, the provider's per-member state (sealed with a key
 * derived from the site secret, so the extension stores nothing itself), attempts and throttling, recovery codes,
 * the sign-in step, admin re-authentication and notices. The provider only checks a response against its state.
 * It is registered with `ExtensionContext::secondFactor()` under a service ID the extension owns, and that ID is
 * the provider ID stored with each enrolment.
 */
interface SecondFactorProvider
{
    /** Translation key of the method's name, e.g. "Authenticator app". */
    public function label(): string;

    /** Route name (owned by the extension) of the page where a member sets the method up or turns it off. */
    public function setupRoute(): string;

    /**
     * Checks a response for the member the challenge is bound to.
     *
     * @param string $state What the provider returned at enrolment or at the last successful check.
     *
     * @return string|null The state to store from now on (for replay protection) when the response is right,
     *     null when it is wrong. Core stores it only if the state was not changed meanwhile.
     */
    public function verify(
        AuthChallenge $challenge,
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $response,
    ): ?string;
}
