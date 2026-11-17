<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Composer\InstalledVersions;
use Nolviko\Core\Container\ContainerBuilder;
use Throwable;

/** Data and integrity checks precede every provider; failures leave a recoverable core-only safe mode. */
final class ExtensionBootstrap
{
    /**
     * Extension API version manifests are checked against (`"engine"`). 1.1.0 (2026-11-17): routes under `/ext/`.
     * Release packages publish the same value as `extension_api` (dev/src/PackageBuilder.php).
     */
    public const API_VERSION = '1.1.0';

    public static function manager(string $root, string $storage): ExtensionManager
    {
        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            $version = InstalledVersions::getVersion($package);
            if ($version !== null) {
                $packages[$package] = $version;
            }
        }

        return new ExtensionManager(
            new ExtensionCatalog($root . '/extensions'),
            new ExtensionState($storage . '/extensions'),
            new ExtensionResolver(new ComposerVersionConstraint(), self::API_VERSION, PHP_VERSION, $packages),
        );
    }

    public static function register(
        ExtensionManager $manager,
        ContainerBuilder $services,
        ExtensionRegistry $registry,
    ): void {
        try {
            $active = $manager->active();
            foreach ($active as $manifest) {
                self::translations($manager->catalog, $manifest, $registry);
                foreach ($manifest->data['providers'] ?? [] as $file) {
                    $provider = self::load($manager->catalog->file($manifest, $file));
                    if (!$provider instanceof ExtensionProvider) {
                        throw new ExtensionFailure('extension_provider_invalid');
                    }

                    $provider->register(
                        new ExtensionContext($manifest, $services, $registry, $manager->state->directory),
                    );
                }
            }
        } catch (Throwable $failure) {
            $manager->state->setSafe(true);
            throw new ExtensionFailure('extension_boot_failed', 0, $failure);
        }
    }

    private static function load(string $file): mixed
    {
        return require $file;
    }

    private static function translations(
        ExtensionCatalog $catalog,
        ExtensionManifest $manifest,
        ExtensionRegistry $registry,
    ): void {
        foreach ($manifest->data['translations'] ?? [] as $locale) {
            $file = $catalog->file($manifest, 'translations/' . $locale . '.json');
            if (filesize($file) > 65536) {
                throw new ExtensionFailure('extension_translation_invalid');
            }

            $messages = json_decode((string) file_get_contents($file), true, 10);
            if (!is_array($messages) || count($messages) > 256) {
                throw new ExtensionFailure('extension_translation_invalid');
            }

            foreach ($messages as $key => $message) {
                if (!is_string($key) || !str_starts_with($key, $manifest->prefix()) || !is_string($message)) {
                    throw new ExtensionFailure('extension_namespace_invalid');
                }

                $registry->messages[$locale][$key] = $message;
            }
        }
    }
}
