<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Extensions\AuthChallenge;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExternalIdentity;
use Nolviko\Core\Extensions\IdentityProvider;
use SensitiveParameter;

/** An outside account service for tests (2026-11-15): code "ok" gives the configured identity. */
final class FakeIdentityProvider implements IdentityProvider
{
    public ?AuthChallenge $lastChallenge = null;

    public string $lastVerifier = '';

    public function __construct(
        public string $subject = '42',
        public ?string $email = 'new@example.com',
        public string $name = 'Yeni Üye',
    ) {
    }

    public function slug(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'auth.external.title';
    }

    public function available(): bool
    {
        return true;
    }

    public function authorizationUrl(
        AuthChallenge $challenge,
        string $redirectUri,
        string $state,
        string $codeChallenge,
    ): string {
        return 'https://id.example/authorize?state=' . rawurlencode($state) . '&challenge=' . $codeChallenge;
    }

    public function identity(
        AuthChallenge $challenge,
        string $redirectUri,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): ExternalIdentity {
        $this->lastChallenge = $challenge;
        $this->lastVerifier = $codeVerifier;
        if ($code !== 'ok') {
            throw new ExtensionFailure('fake_rejected');
        }

        return new ExternalIdentity($challenge->provider, $this->subject, $this->email, $this->name);
    }
}
