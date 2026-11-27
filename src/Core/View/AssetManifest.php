<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use RuntimeException;

/**
 * Fingerprinted static CSS/JS distribution. Source files remain the development fallback; releases use JSON.
 */
final class AssetManifest
{
    /** @var array<string, string> */
    private readonly array $files;

    public function __construct(private readonly string $root)
    {
        $path = $root . '/config/assets.json';
        $this->files = is_file($path)
            ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
            : [];
    }

    public function path(string $name): string
    {
        self::assertName($name);
        $file = $this->files[$name] ?? $name;
        self::assertName($file);

        if (!is_file($this->root . '/public/assets/' . $file)) {
            throw new RuntimeException('asset_missing');
        }

        if (!isset($this->files[$name])) {
            // A development checkout has no fingerprinted copies (2026-11-27): the file's change time in the
            // address makes browsers fetch an edited file at once instead of after the one-day cache.
            return 'assets/' . $file . '?v=' . (int) filemtime($this->root . '/public/assets/' . $file);
        }

        return 'assets/' . $file;
    }

    /**
     * Builds only in a private release stage, before it is published. No minifier or Node is required.
     *
     * @return array<string, string>
     */
    public static function build(string $root): array
    {
        $files = [];

        foreach (glob($root . '/public/assets/*') ?: [] as $source) {
            $name = basename($source);
            self::assertName($name);

            if (is_link($source) || !is_file($source)) {
                throw new RuntimeException('asset_source_invalid');
            }

            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $body = ThemeAssetBuilder::build($name, (string) file_get_contents($source));
            $file = $stem . '.' . hash('sha256', $body) . '.' . $extension;

            if (file_put_contents($root . '/public/assets/' . $file, $body) !== strlen($body)) {
                throw new RuntimeException('asset_build_failed');
            }

            $files[$name] = $file;
        }

        ksort($files);
        $json = json_encode($files, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        if (file_put_contents($root . '/config/assets.json', $json) === false) {
            throw new RuntimeException('asset_manifest_failed');
        }

        return $files;
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]*\.(?:css|js)$/D', $name) !== 1) {
            throw new RuntimeException('asset_name_invalid');
        }
    }
}
