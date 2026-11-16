<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;

/**
 * The second-factor providers enabled extensions registered (2026-11-15), built lazily: nothing is constructed
 * until a sign-in or setup page asks. Keys are the provider IDs (service IDs owned by the extension).
 */
final class SecondFactorProviders
{
    /** @var array<string, SecondFactorProvider> */
    private array $built = [];

    /**
     * @param array<string, Closure(): object> $factories Provider ID => container factory.
     * @param array<string, string> $owners Provider ID => extension ID.
     */
    public function __construct(
        private readonly array $factories = [],
        private readonly array $owners = [],
    ) {
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->factories);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    public function get(string $id): SecondFactorProvider
    {
        if (isset($this->built[$id])) {
            return $this->built[$id];
        }

        $provider = isset($this->factories[$id]) ? ($this->factories[$id])() : null;
        if (!$provider instanceof SecondFactorProvider) {
            throw new ExtensionFailure('extension_second_factor_invalid');
        }

        return $this->built[$id] = $provider;
    }

    /** The extension that registered the provider, as AuthChallenge expects it. */
    public function owner(string $id): string
    {
        return $this->owners[$id] ?? throw new ExtensionFailure('extension_second_factor_invalid');
    }
}
