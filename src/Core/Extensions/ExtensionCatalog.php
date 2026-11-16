<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Bounded two-level discovery; invalid packages are diagnostic entries, never executed. */
final readonly class ExtensionCatalog
{
    public function __construct(
        public string $directory,
    ) {
    }

    /** @return array{manifests: array<string, ExtensionManifest>, errors: array<string, string>} */
    public function discover(): array
    {
        $result = ['manifests' => [], 'errors' => []];
        if (!is_dir($this->directory)) {
            return $result;
        }

        if (is_link($this->directory)) {
            throw new ExtensionFailure('extension_path_invalid');
        }

        $vendors = glob($this->directory . '/*', GLOB_ONLYDIR) ?: [];
        if (count($vendors) > 128) {
            throw new ExtensionFailure('extension_catalog_limit');
        }

        foreach ($vendors as $vendor) {
            if (is_link($vendor)) {
                continue;
            }

            foreach (glob($vendor . '/*', GLOB_ONLYDIR) ?: [] as $path) {
                if (count($result['manifests']) + count($result['errors']) >= 128) {
                    throw new ExtensionFailure('extension_catalog_limit');
                }

                $id = basename($vendor) . '/' . basename($path);
                try {
                    $file = $path . '/extension.json';
                    if (is_link($path) || is_link($file) || !is_file($file) || filesize($file) > 65536) {
                        throw new ExtensionFailure('extension_manifest_invalid');
                    }

                    $manifest = ExtensionManifest::parse((string) file_get_contents($file));
                    if ($manifest->id !== $id) {
                        throw new ExtensionFailure('extension_identity_mismatch');
                    }

                    $result['manifests'][$id] = $manifest;
                } catch (ExtensionFailure $failure) {
                    $result['errors'][$id] = $failure->getMessage();
                }
            }
        }

        ksort($result['manifests']);
        ksort($result['errors']);

        return $result;
    }

    public function file(ExtensionManifest $manifest, string $relative): string
    {
        ExtensionManifest::path($relative);
        $base = realpath($this->directory . '/' . $manifest->id);
        $path = $this->directory . '/' . $manifest->id . '/' . $relative;
        $resolved = realpath($path);
        if ($base === false || $resolved === false || !is_file($resolved)) {
            throw new ExtensionFailure('extension_path_invalid');
        }

        $cursor = $this->directory;
        foreach (explode('/', $manifest->id . '/' . $relative) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new ExtensionFailure('extension_path_invalid');
            }
        }

        if (!str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $base) . '/')) {
            throw new ExtensionFailure('extension_path_invalid');
        }

        return $resolved;
    }
}
