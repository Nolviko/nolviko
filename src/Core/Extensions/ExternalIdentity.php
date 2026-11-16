<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Provider-scoped identity; email alone must never authorize linking to an existing account. */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $verifiedEmail,
        /** Display name from the provider, only to suggest a username (2026-11-15). */
        public string $name = '',
    ) {
        if (
            preg_match(ExtensionManifest::ID_PATTERN, $provider) !== 1 || $subject === '' || strlen($subject) > 255
            || $verifiedEmail !== null && filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new ExtensionFailure('extension_auth_identity_invalid');
        }
    }
}
