<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;

/** Serialized lifecycle changes; incompatible updates never replace the active version or execute providers. */
final readonly class ExtensionManager
{
    public function __construct(
        public ExtensionCatalog $catalog,
        public ExtensionState $state,
        private ExtensionResolver $resolver,
    ) {
    }

    /** @return list<ExtensionManifest> */
    public function active(): array
    {
        if ($this->state->safe()) {
            return [];
        }

        $enabled = $this->state->enabled();
        if ($enabled === []) {
            return [];
        }

        $manifests = $this->catalog->discover()['manifests'];
        $ordered = $this->resolver->resolve($manifests, array_keys($enabled));
        foreach ($ordered as $manifest) {
            $entry = $enabled[$manifest->id];
            if (
                $entry['version'] !== $manifest->version
                || !hash_equals(
                    $entry['digest'],
                    ExtensionIntegrity::digest($this->catalog->directory . '/' . $manifest->id),
                )
            ) {
                throw new ExtensionFailure('extension_integrity_changed');
            }
        }

        return $ordered;
    }

    /** @param Closure(ExtensionManifest): void $migrate */
    public function enable(string $id, Closure $migrate): void
    {
        $this->state->locked(function () use ($id, $migrate): void {
            $enabled = $this->state->enabled();
            $manifests = $this->catalog->discover()['manifests'];
            $manifest = $manifests[$id] ?? throw new ExtensionFailure('extension_dependency_missing');
            $this->resolver->resolve($manifests, array_values(array_unique([...array_keys($enabled), $id])));
            foreach ($manifest->data['providers'] ?? [] as $file) {
                $this->catalog->file($manifest, $file);
            }

            $digest = ExtensionIntegrity::digest($this->catalog->directory . '/' . $id);
            $migrate($manifest);
            if (!hash_equals($digest, ExtensionIntegrity::digest($this->catalog->directory . '/' . $id))) {
                throw new ExtensionFailure('extension_integrity_changed');
            }

            $enabled[$id] = ['version' => $manifest->version, 'digest' => $digest];
            $this->state->write($enabled);
        });
    }

    public function resume(): void
    {
        $this->state->locked(function (): void {
            $this->assertEnabled();
            $this->state->setSafe(false);
        });
    }

    public function disableAll(): void
    {
        $this->state->locked(fn() => $this->state->write([]));
    }

    public function assertRecovery(ExtensionManifest $candidate): void
    {
        $manifests = $this->catalog->discover()['manifests'];
        $manifests[$candidate->id] = $candidate;
        $this->resolver->resolve($manifests, [...array_keys($this->state->enabled()), $candidate->id]);
    }

    private function assertEnabled(): void
    {
        $enabled = $this->state->enabled();
        $ordered = $this->resolver->resolve($this->catalog->discover()['manifests'], array_keys($enabled));
        foreach ($ordered as $manifest) {
            $entry = $enabled[$manifest->id];
            if (
                $entry['version'] !== $manifest->version
                || !hash_equals(
                    $entry['digest'],
                    ExtensionIntegrity::digest($this->catalog->directory . '/' . $manifest->id),
                )
            ) {
                throw new ExtensionFailure('extension_integrity_changed');
            }
        }
    }

    public function disable(string $id): void
    {
        $this->state->locked(function () use ($id): void {
            $enabled = $this->state->enabled();
            unset($enabled[$id]);
            $this->resolver->resolve($this->catalog->discover()['manifests'], array_keys($enabled));
            $this->state->write($enabled);
        });
    }

    /** @return array<string, string> */
    public function diagnostics(): array
    {
        $errors = $this->catalog->discover()['errors'];
        if ($this->state->hasBootFailure()) {
            $errors['previous_boot'] = 'extension_boot_failed';
        }
        try {
            $this->active();
        } catch (ExtensionFailure $failure) {
            $errors['runtime'] = $failure->getMessage();
        }

        return $errors;
    }

    public function assertCandidate(ExtensionManifest $candidate): void
    {
        $enabled = $this->state->enabled();
        $manifests = $this->catalog->discover()['manifests'];
        $current = $manifests[$candidate->id] ?? null;
        if ($current !== null && !$this->resolver->newer($candidate->version, $current->version)) {
            throw new ExtensionFailure('extension_update_not_newer');
        }

        $manifests[$candidate->id] = $candidate;
        $this->resolver->resolve($manifests, array_values(array_unique([...array_keys($enabled), $candidate->id])));
    }
}
