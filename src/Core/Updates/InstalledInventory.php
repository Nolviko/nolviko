<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/** Previous packaged ownership list, never an ownership guess based on a scan of the live tree. */
final class InstalledInventory
{
    public static function load(string $site): array
    {
        $path = $site . '/config/runtime-files.json';
        if (is_link($path) || !is_file($path) || filesize($path) > 2097152) {
            throw new RuntimeException('update_inventory_missing');
        }
        $raw = (string) file_get_contents($path);
        $files = json_decode($raw, true, 4);
        if (
            !is_array($files) || array_is_list($files) || count($files) > SignedReleaseManifest::MAX_FILES
            || !isset($files['src/Core/Updates/SiteAccessLock.php'])
        ) {
            throw new RuntimeException('update_inventory_invalid');
        }
        foreach ($files as $name => $hash) {
            if (
                !is_string($name) || ArchivePath::normalize($name) !== $name
                || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || preg_match(
                    '~^(?:storage/|extensions/|config/(?:local|install|maintenance|update-trust|import-sources))~',
                    $name
                ) === 1 && !in_array($name, ['storage/.htaccess', 'extensions/.htaccess'], true)
            ) {
                throw new RuntimeException('update_inventory_invalid');
            }
        }
        $files['config/runtime-files.json'] = hash('sha256', $raw);
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function intact(string $site, array $files): bool
    {
        foreach ($files as $path => $hash) {
            $file = $site . '/' . $path;
            if (is_link($file) || !is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) {
                return false;
            }
            $parent = dirname($file);
            while ($parent !== $site && strlen($parent) > strlen($site)) {
                if (is_link($parent)) {
                    return false;
                }
                $parent = dirname($parent);
            }
        }
        return true;
    }
}
