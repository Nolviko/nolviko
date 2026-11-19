<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Closure;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Container\Container;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Container\ContainerException;
use Nolviko\Core\Container\Lazy;
use Nolviko\Core\Container\ServiceProvider;
use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionHandlers;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Extensions\IdentityProviders;
use Nolviko\Core\Extensions\SecondFactorProviders;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\ErrorReporter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one place where the object graph is assembled (ADR-005: explicit composition root, no autowiring).
 *
 * The graph is validated on every build, so a missing or circular dependency fails at boot with a
 * diagnostic ID, in development, tests and production alike; validation cost is in the benchmark.
 */
final class CompositionRoot
{
    public const ROOT = 'app.root';

    public const STORAGE = 'app.storage';

    /** Directory holding local.php and install.lock (the installer writes there). */
    public const CONFIG_DIRECTORY = 'app.config_directory';

    /** Config|null: null before installation (InstallationState::NotConfigured). */
    public const CONFIG = 'app.config';

    /**
     * Providers in registration order.
     *
     * Extension registration point (docs/EXTENSIONS.md): enabled extensions' providers will be appended
     * after the core provider, from the validated extension registry (M06). Nothing is loaded yet, and an
     * extension may only add services; redefining a core ID fails as `container_service_duplicate`.
     *
     * @return list<ServiceProvider>
     */
    public static function providers(): array
    {
        return [new CoreServiceProvider()];
    }

    /**
     * @param LoggerInterface|null $logger Null means a NullLogger.
     * @param CspNonce|null $nonce The request's CSP nonce; null generates one.
     * @param list<ServiceProvider>|null $providers Null means providers().
     * @param string|null $configDirectory Null means `<root>/config`.
     *
     * @throws ContainerException When the wiring is invalid.
     */
    public static function build(
        string $root,
        string $storage,
        ?Config $config,
        InstallationState $state,
        ErrorReporter $reporter,
        ?LoggerInterface $logger = null,
        ?CspNonce $nonce = null,
        ?array $providers = null,
        ?string $configDirectory = null,
    ): Container {
        $services = new ContainerBuilder();
        $services->value(self::ROOT, $root);
        $services->value(self::STORAGE, $storage);
        $services->value(self::CONFIG, $config);
        $services->value(self::CONFIG_DIRECTORY, $configDirectory ?? $root . '/config');
        $services->value(InstallationState::class, $state);
        $services->value(ErrorReporter::class, $reporter);
        $services->value(LoggerInterface::class, $logger ?? new NullLogger());
        $services->value(CspNonce::class, $nonce ?? CspNonce::generate());

        $manager = ExtensionBootstrap::manager($root, $storage);
        $registry = new ExtensionRegistry();
        $services->value(ExtensionManager::class, $manager);
        $services->value(ExtensionRegistry::class, $registry);
        $services->value(BeforeHooks::class, $registry->before);

        foreach ($providers ?? self::providers() as $provider) {
            $provider->register($services);
        }

        $wasSafe = $manager->state->safe();
        try {
            ExtensionBootstrap::register($manager, $services, $registry);

            $routes = array_keys($registry->routes);
            $services->factory(
                ExtensionHandlers::class,
                static fn(Closure ...$handlers): ExtensionHandlers => new ExtensionHandlers(
                    array_combine($routes, $handlers),
                ),
                array_map(static fn(string $route): Lazy => new Lazy($route . '.handler'), $routes),
            );
            $factors = $registry->factors;
            $services->factory(
                SecondFactorProviders::class,
                static fn(Closure ...$providers): SecondFactorProviders => new SecondFactorProviders(
                    array_combine(array_keys($factors), $providers),
                    $factors,
                ),
                array_map(static fn(string $id): Lazy => new Lazy($id), array_keys($factors)),
            );

            $identities = $registry->identities;
            $services->factory(
                IdentityProviders::class,
                static fn(Closure ...$providers): IdentityProviders => new IdentityProviders(
                    array_combine(array_keys($identities), $providers),
                    $identities,
                ),
                array_map(static fn(string $id): Lazy => new Lazy($id), array_keys($identities)),
            );
            $container = $services->build();
            if (!$wasSafe && $manager->state->enabled() !== [] && $manager->state->hasBootFailure()) {
                // The extensions booted: an older failure is resolved.
                try {
                    $manager->state->clearFailure();
                } catch (ExtensionFailure) {
                    // A marker that cannot be removed only keeps the old notice; it never stops the forum.
                }
            }
            // Extension hooks reach their own services lazily through ExtensionContext::lazy() (2026-11-15).
            $registry->resolver = static fn(string $id): mixed => $container->get($id);

            return $container;
        } catch (ExtensionFailure | ContainerException $failure) {
            if ($wasSafe || $failure instanceof ContainerException && $manager->state->enabled() === []) {
                throw $failure;
            }

            $manager->state->rememberFailure();
            if (!$manager->state->safe()) {
                $manager->state->setSafe(true);
            }

            return self::build(
                $root,
                $storage,
                $config,
                $state,
                $reporter,
                $logger,
                $nonce,
                $providers,
                $configDirectory,
            );
        }
    }
}
