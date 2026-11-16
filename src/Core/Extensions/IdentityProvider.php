<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use SensitiveParameter;

/**
 * Sign in with an outside account (ADR-029; first used by the Google extension, 2026-11-15).
 *
 * Core owns the flow: state and nonce bound to the session, PKCE, the exact callback address, the account lookup,
 * sign-up (only with a verified address and never onto an existing account), linking and unlinking, bans and the
 * second sign-in step. The provider only builds the authorization address and turns the returned code into a
 * checked identity. It is registered with `ExtensionContext::identityProvider()`.
 */
interface IdentityProvider
{
    /** Lowercase word used in addresses, e.g. `google` in /login/external/google. */
    public function slug(): string;

    /** Translation key of the button text, e.g. "Sign in with Google". */
    public function label(): string;

    /** False while the site lacks what the provider needs (client ID and secret, HTTPS support). */
    public function available(): bool;

    /**
     * @param string $state Opaque value to send back unchanged.
     * @param string $codeChallenge PKCE S256 challenge.
     */
    public function authorizationUrl(
        AuthChallenge $challenge,
        string $redirectUri,
        string $state,
        string $codeChallenge,
    ): string;

    /**
     * Exchanges the code (with the PKCE verifier) and checks the answer: issuer, audience, expiry and the
     * challenge's nonce. The email is set only when the provider says it is verified.
     *
     * @throws ExtensionFailure When the code or the answer is not valid.
     */
    public function identity(
        AuthChallenge $challenge,
        string $redirectUri,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): ExternalIdentity;
}
