<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Opaque, expiring challenge bound to a core user and purpose; never contains a reusable secret. */
final readonly class AuthChallenge
{
    public function __construct(
        public string $provider,
        public int $userId,
        public string $purpose,
        public string $nonce,
        public int $expiresAt,
    ) {
        if (
            // 2026-11-15: a sign-in challenge exists before the member is known (user 0).
            preg_match(ExtensionManifest::ID_PATTERN, $provider) !== 1 || $userId < 0
            || ($userId === 0 && $purpose !== 'sign_in')
            || !in_array($purpose, ['sign_in', 'enroll', 'remove', 'admin_reauth', 'account_link'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $nonce) !== 1 || $expiresAt < 1
        ) {
            throw new ExtensionFailure('extension_auth_challenge_invalid');
        }
    }
}
