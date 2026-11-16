<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Deterministic dependency order with explicit engine, PHP and shared-package isolation gates. */
final readonly class ExtensionResolver
{
    /** @param array<string, string> $packages Installed Composer versions, never loaded from extension vendor/. */
    public function __construct(
        private VersionConstraint $versions,
        private string $engine,
        private string $php,
        private array $packages = [],
    ) {
    }

    public function newer(string $candidate, string $installed): bool
    {
        return $this->versions->accepts($candidate, '>' . $installed);
    }

    /** @param array<string, ExtensionManifest> $manifests
     * @param list<string> $enabled
     * @return list<ExtensionManifest>
     */
    public function resolve(array $manifests, array $enabled): array
    {
        $enabled = array_values(array_unique($enabled));
        sort($enabled);
        $pending = [];
        foreach ($enabled as $id) {
            $manifest = $manifests[$id] ?? null;
            if ($manifest === null) {
                throw new ExtensionFailure('extension_dependency_missing');
            }

            if (
                !$this->versions->accepts($this->engine, $manifest->data['engine'])
                || !$this->versions->accepts($this->php, $manifest->data['php'])
            ) {
                throw new ExtensionFailure('extension_incompatible');
            }

            foreach ($manifest->data['packages'] ?? [] as $package => $range) {
                if (!isset($this->packages[$package]) || !$this->versions->accepts($this->packages[$package], $range)) {
                    throw new ExtensionFailure('extension_package_conflict');
                }
            }

            foreach ($manifest->data['depends_on'] ?? [] as $dependency => $range) {
                if (!in_array($dependency, $enabled, true) || !isset($manifests[$dependency])) {
                    throw new ExtensionFailure('extension_dependency_missing');
                }

                if (!$this->versions->accepts($manifests[$dependency]->version, $range)) {
                    throw new ExtensionFailure('extension_dependency_incompatible');
                }
            }

            foreach ($manifest->data['conflicts'] ?? [] as $conflict => $range) {
                if (
                    in_array($conflict, $enabled, true)
                    && $this->versions->accepts($manifests[$conflict]->version, $range)
                ) {
                    throw new ExtensionFailure('extension_conflict');
                }
            }

            $pending[$id] = $manifest;
        }

        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $manifest) {
                if (array_intersect(array_keys($manifest->data['depends_on'] ?? []), array_keys($pending)) !== []) {
                    continue;
                }

                $ordered[] = $manifest;
                unset($pending[$id]);
                $progress = true;
            }

            if (!$progress) {
                throw new ExtensionFailure('extension_dependency_cycle');
            }
        }

        return $ordered;
    }
}
