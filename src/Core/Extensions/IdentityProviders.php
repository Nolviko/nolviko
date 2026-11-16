<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;
use Throwable;

/**
 * The identity providers enabled extensions registered (2026-11-15), built on first use and looked up by their
 * address word (slug). A provider that fails to build or is not configured is simply not offered.
 */
final class IdentityProviders
{
    /** @var array<string, IdentityProvider>|null Slug => provider. */
    private ?array $bySlug = null;

    /** @var array<string, string> Slug => extension ID. */
    private array $owners = [];

    /**
     * @param array<string, Closure(): object> $factories Service ID => container factory.
     * @param array<string, string> $extensions Service ID => extension ID.
     */
    public function __construct(
        private readonly array $factories = [],
        private readonly array $extensions = [],
    ) {
    }

    /** @return array<string, IdentityProvider> Available providers by slug. */
    public function available(): array
    {
        if ($this->bySlug !== null) {
            return $this->bySlug;
        }

        $this->bySlug = [];
        foreach ($this->factories as $id => $factory) {
            try {
                $provider = $factory();
                $slug = $provider instanceof IdentityProvider ? $provider->slug() : '';
                if (
                    preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $slug) === 1 && !isset($this->bySlug[$slug])
                    && $provider->available()
                ) {
                    $this->bySlug[$slug] = $provider;
                    $this->owners[$slug] = $this->extensions[$id] ?? '';
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $this->bySlug;
    }

    public function get(string $slug): ?IdentityProvider
    {
        return $this->available()[$slug] ?? null;
    }

    /** The extension ID (ExternalIdentity::$provider, AuthChallenge::$provider) of a provider. */
    public function owner(string $slug): string
    {
        $this->available();

        return $this->owners[$slug] ?? throw new ExtensionFailure('extension_identity_provider_invalid');
    }
}
