<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use ZipArchive;

/** Trusted PHP packages still undergo bounded ZIP validation; staging never executes uploaded code. */
final readonly class ExtensionArchive
{
    private const FILE_PATTERN = '~^(?:extension\.json|LICENSE(?:\.txt)?|'
        . '(?:src|migrations|views|assets|translations)/.+\.(?:php|twig|css|js|json|txt))$~D';
    public function __construct(
        private ExtensionManager $manager,
    ) {
    }

    /**
     * Unpacks an uploaded ZIP into a private stage and returns its token. Owner decision 2026-11-17: the
     * administrator no longer types the package's SHA-256; the upload itself (admin session, CSRF, one
     * confirmation) is the trust decision. The stage still gets its own digest, so `install()` refuses files that
     * changed between the two steps.
     */
    public function stage(string $archive): string
    {
        if (!is_file($archive) || is_link($archive) || filesize($archive) > 4194304) {
            throw new ExtensionFailure('extension_package_invalid');
        }

        return $this->manager->state->locked(function () use ($archive): string {
            $retained = count(glob($this->manager->state->directory . '/previous-*', GLOB_ONLYDIR) ?: [])
                + count(glob($this->manager->state->directory . '/replaced-*', GLOB_ONLYDIR) ?: []);
            if (
                count(glob($this->manager->state->directory . '/stage-*', GLOB_ONLYDIR) ?: []) >= 32
                || $retained >= 128
            ) {
                throw new ExtensionFailure('extension_package_limit');
            }

            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) {
                throw new ExtensionFailure('extension_package_invalid');
            }

            try {
                if ($zip->numFiles < 2 || $zip->numFiles > 300) {
                    throw new ExtensionFailure('extension_package_limit');
                }

                $files = [];
                $size = 0;
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $stat = $zip->statIndex($index);
                    $name = $stat['name'];
                    ExtensionManifest::path($name);
                    $zip->getExternalAttributesIndex($index, $system, $attributes);
                    $mode = ($attributes >> 16) & 0170000;
                    if (
                        isset($files[strtolower($name)]) || !in_array($mode, [0, 0100000], true)
                        || preg_match(self::FILE_PATTERN, $name) !== 1
                        || $stat['size'] > 1048576
                    ) {
                        throw new ExtensionFailure('extension_package_invalid');
                    }

                    $size += $stat['size'];
                    if ($size > 8388608) {
                        throw new ExtensionFailure('extension_package_limit');
                    }

                    $content = $zip->getFromIndex($index, 1048577);
                    if (!is_string($content) || strlen($content) !== $stat['size']) {
                        throw new ExtensionFailure('extension_package_invalid');
                    }

                    $files[strtolower($name)] = [$name, $content];
                }

                $manifest = ExtensionManifest::parse($files['extension.json'][1] ?? '');
                $this->manager->assertCandidate($manifest);
                $token = bin2hex(random_bytes(16));
                $stage = $this->manager->state->directory . '/stage-' . $token;
                if (!mkdir($stage, 0700)) {
                    throw new ExtensionFailure('extension_state_write_failed');
                }

                foreach ($files as [$name, $content]) {
                    $directory = dirname($stage . '/' . $name);
                    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                        throw new ExtensionFailure('extension_state_write_failed');
                    }

                    if (file_put_contents($stage . '/' . $name, $content, LOCK_EX) !== strlen($content)) {
                        throw new ExtensionFailure('extension_state_write_failed');
                    }
                }

                $receipt = ExtensionIntegrity::digest($stage);
                if (file_put_contents($stage . '.sha256', $receipt, LOCK_EX) !== 64) {
                    throw new ExtensionFailure('extension_state_write_failed');
                }

                return $token;
            } finally {
                $zip->close();
            }
        });
    }

    public function rollback(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new ExtensionFailure('extension_package_invalid');
        }

        $this->manager->state->locked(function () use ($token): void {
            $previous = $this->manager->state->directory . '/previous-' . $token;
            ExtensionIntegrity::digest($previous);
            $manifest = ExtensionManifest::parse((string) file_get_contents($previous . '/extension.json'));
            $this->manager->assertRecovery($manifest);
            if (isset($this->manager->state->enabled()[$manifest->id])) {
                throw new ExtensionFailure('extension_update_requires_disable');
            }

            $target = $this->manager->catalog->directory . '/' . $manifest->id;
            ExtensionIntegrity::digest($target);
            $failed = $this->manager->state->directory . '/replaced-' . $token;
            if (file_exists($failed) || !rename($target, $failed)) {
                throw new ExtensionFailure('extension_state_write_failed');
            }

            if (!rename($previous, $target)) {
                rename($failed, $target);
                throw new ExtensionFailure('extension_state_write_failed');
            }
        });
    }

    /**
     * Kept copies an update replaced (2026-11-17, restore button): extension ID => newest kept copy.
     *
     * @return array<string, array{token: string, version: string}>
     */
    public function previousVersions(): array
    {
        $kept = [];
        foreach (glob($this->manager->state->directory . '/previous-*', GLOB_ONLYDIR) ?: [] as $directory) {
            $token = substr(basename($directory), strlen('previous-'));
            $json = $directory . '/extension.json';
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || is_link($directory) || !is_file($json)) {
                continue;
            }
            try {
                $manifest = ExtensionManifest::parse((string) file_get_contents($json));
            } catch (ExtensionFailure) {
                continue;
            }
            $time = (int) filemtime($directory);
            if (!isset($kept[$manifest->id]) || $time > $kept[$manifest->id]['time']) {
                $kept[$manifest->id] = ['token' => $token, 'version' => $manifest->version, 'time' => $time];
            }
        }

        return array_map(
            static fn(array $copy): array => ['token' => $copy['token'], 'version' => $copy['version']],
            $kept,
        );
    }

    /** The extension ID inside a staged package (one-step upload: an update disables it before install). */
    public function stagedId(string $token): string
    {
        $manifest = $this->manager->state->directory . '/stage-' . $token . '/extension.json';
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || is_link($manifest) || !is_file($manifest)) {
            throw new ExtensionFailure('extension_package_invalid');
        }

        return ExtensionManifest::parse((string) file_get_contents($manifest))->id;
    }

    public function install(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new ExtensionFailure('extension_package_invalid');
        }

        $this->manager->state->locked(function () use ($token): void {
            $stage = $this->manager->state->directory . '/stage-' . $token;
            $receipt = $stage . '.sha256';
            if (
                is_link($receipt) || !is_file($receipt)
                || !hash_equals((string) file_get_contents($receipt), ExtensionIntegrity::digest($stage))
            ) {
                throw new ExtensionFailure('extension_integrity_changed');
            }
            $manifest = ExtensionManifest::parse((string) file_get_contents($stage . '/extension.json'));
            $this->manager->assertCandidate($manifest);
            $enabled = $this->manager->state->enabled();
            if (isset($enabled[$manifest->id])) {
                throw new ExtensionFailure('extension_update_requires_disable');
            }

            $target = $this->manager->catalog->directory . '/' . $manifest->id;
            $parent = dirname($target);
            if (is_link($parent) || is_link($target)) {
                throw new ExtensionFailure('extension_path_invalid');
            }

            if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                throw new ExtensionFailure('extension_state_write_failed');
            }

            $previous = $this->manager->state->directory . '/previous-' . $token;
            $hadPrevious = is_dir($target);
            if ($hadPrevious && !rename($target, $previous)) {
                throw new ExtensionFailure('extension_state_write_failed');
            }

            if (!rename($stage, $target)) {
                if ($hadPrevious) {
                    rename($previous, $target);
                }

                throw new ExtensionFailure('extension_state_write_failed');
            }
        });
    }
}
