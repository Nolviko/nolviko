<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;
use ZipArchive;

/** Metadata is read only from the exact authenticated ZIP; it never runs target PHP. */
final readonly class ReleaseCompatibility
{
    private function __construct(public array $data, public ReleaseVersion $version)
    {
    }

    public static function fromArchive(string $path, SignedReleaseManifest $manifest): self
    {
        if (
            is_link($path) || !is_file($path) || filesize($path) !== $manifest->archiveBytes
            || !hash_equals($manifest->archiveHash, (string) hash_file('sha256', $path))
        ) {
            throw new RuntimeException('update_archive_identity_invalid');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('update_archive_invalid');
        }
        try {
            ReleaseArchivePlan::inspect($zip, $manifest);
            $stat = $zip->statName('config/release-compatibility.json');
            if ($stat === false || $stat['size'] > 65536) {
                throw new RuntimeException('update_compatibility_missing');
            }
            return self::parse((string) $zip->getFromName('config/release-compatibility.json'));
        } finally {
            $zip->close();
        }
    }

    public static function parse(string $json): self
    {
        $data = strlen($json) <= 65536 ? json_decode($json, true, 12) : null;
        $keys = ['format', 'version', 'extension_api', 'theme_api', 'php', 'databases', 'extensions', 'packages'];
        if (
            !is_array($data) || array_diff(array_keys($data), $keys) !== []
            || array_diff($keys, array_keys($data)) !== [] || $data['format'] !== 1 || $data['theme_api'] !== 1
            || !is_string($data['version']) || !is_string($data['extension_api']) || !is_string($data['php'])
            || !is_array($data['databases']) || !is_array($data['extensions']) || !is_array($data['packages'])
            || count($data['extensions']) > 64 || count($data['packages']) > 128
        ) {
            throw new RuntimeException('update_compatibility_invalid');
        }
        new ReleaseVersion($data['extension_api']);
        foreach (['databases', 'packages'] as $map) {
            foreach ($data[$map] as $name => $value) {
                if (!is_string($name) || !is_string($value) || strlen($name) > 100 || strlen($value) > 128) {
                    throw new RuntimeException('update_compatibility_invalid');
                }
            }
        }
        foreach ($data['extensions'] as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $extension) !== 1) {
                throw new RuntimeException('update_compatibility_invalid');
            }
        }
        return new self($data, new ReleaseVersion($data['version']));
    }
}
