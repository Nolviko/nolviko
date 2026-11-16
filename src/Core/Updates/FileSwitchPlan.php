<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;
use ZipArchive;

/**
 * Activation inventory derived from authenticated ZIP bytes and an explicitly trusted installed-core inventory.
 */
final readonly class FileSwitchPlan
{
    /**
     * @param list<array{path: string, hash: string|null, old: string|null, size: int}> $operations
     */
    private function __construct(
        public string $manifestHash,
        public string $staging,
        public array $operations,
        public string $hash,
    ) {
    }

    /**
     * @param array<string, string> $installed Owned core paths => hashes from the trusted previous release inventory.
     */
    public static function prepare(
        string $archivePath,
        SignedReleaseManifest $manifest,
        string $staging,
        array $installed,
    ): self {
        $root = realpath($staging);

        if (
            $root === false || is_link($staging) || !is_dir($root)
            || is_link($archivePath) || !is_file($archivePath)
            || filesize($archivePath) !== $manifest->archiveBytes
            || hash_file('sha256', $archivePath) !== $manifest->archiveHash
            || count($installed) > SignedReleaseManifest::MAX_FILES
        ) {
            throw new RuntimeException('update_switch_source_invalid');
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('update_switch_archive_invalid');
        }

        $operations = [];
        $paths = [];

        try {
            $plan = ReleaseArchivePlan::inspect($zip, $manifest);

            foreach ($plan->entries as $entry) {
                if ($entry['directory']) {
                    continue;
                }

                self::path($entry['path']);
                self::regularParents($root, $entry['path']);
                $file = $root . '/' . $entry['path'];
                $stream = $zip->getStream($zip->getNameIndex($entry['index']));

                if ($stream === false) {
                    throw new RuntimeException('update_switch_archive_invalid');
                }

                try {
                    $hash = hash_init('sha256');
                    $bytes = hash_update_stream($hash, $stream, $entry['size'] + 1);
                    $digest = hash_final($hash);
                } finally {
                    fclose($stream);
                }

                if (
                    $bytes !== $entry['size'] || !is_file($file) || filesize($file) !== $bytes
                    || hash_file('sha256', $file) !== $digest
                ) {
                    throw new RuntimeException('update_switch_stage_changed');
                }

                $paths[$entry['path']] = true;
                $operations[$entry['path']] = [
                    'path' => $entry['path'],
                    'hash' => $digest,
                    'old' => $installed[$entry['path']] ?? null,
                    'size' => $bytes,
                ];
            }
        } finally {
            $zip->close();
        }

        foreach ($installed as $path => $hash) {
            self::path($path);

            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new RuntimeException('update_switch_inventory_invalid');
            }

            $paths[$path] = true;

            if (!isset($operations[$path])) {
                $operations[$path] = ['path' => $path, 'hash' => null, 'old' => $hash, 'size' => 0];
            }
        }

        // Refuse case aliases and file/directory shape changes instead of deleting unknown directory contents.
        $spellings = [];
        $files = array_fill_keys(array_map('strtolower', array_keys($paths)), true);

        foreach (array_keys($paths) as $path) {
            $prefix = '';
            $segments = explode('/', $path);

            foreach ($segments as $index => $segment) {
                $prefix .= ($prefix === '' ? '' : '/') . $segment;
                $key = strtolower($prefix);

                if (
                    (isset($spellings[$key]) && $spellings[$key] !== $prefix)
                    || ($index < count($segments) - 1 && isset($files[$key]))
                ) {
                    throw new RuntimeException('update_switch_path_collision');
                }

                $spellings[$key] = $prefix;
            }
        }

        ksort($operations, SORT_STRING);
        // Replacements precede removal of obsolete owned files. A checkpoint is committed per operation.
        $ordered = array_merge(
            array_values(array_filter($operations, static fn(array $entry): bool => $entry['hash'] !== null)),
            array_values(array_filter($operations, static fn(array $entry): bool => $entry['hash'] === null)),
        );
        $identity = json_encode([$manifest->manifestHash, $root, $ordered], JSON_THROW_ON_ERROR);

        return new self($manifest->manifestHash, $root, $ordered, hash('sha256', $identity));
    }

    public static function path(string $path): void
    {
        if (
            ArchivePath::normalize($path) !== $path
            || preg_match(
                '~^(?:\.env(?:[./]|$)|config/(?:local\.php|install\.lock|mainte'
                    . 'nance\.lock|update-trust\.json|import-sources\.json)(?:/|$))~i',
                $path,
            ) === 1
            || strtolower($path) === 'storage'
            || (str_starts_with(strtolower($path), 'storage/') && $path !== 'storage/.htaccess')
            || preg_match('~^public/update-recovery\.php(?:/|$)~i', $path) === 1
        ) {
            throw new RuntimeException('update_switch_protected_path');
        }
    }

    public static function regularParents(string $root, string $path): void
    {
        $current = $root;
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            $current .= '/' . $segment;

            if (is_link($current) || ($index < count($segments) - 1 && file_exists($current) && !is_dir($current))) {
                throw new RuntimeException('update_switch_link_or_parent_invalid');
            }
        }
    }
}
