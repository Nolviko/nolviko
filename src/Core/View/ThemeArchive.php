<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Nolviko\Core\Locale\TranslatorFactory;
use Throwable;
use ZipArchive;

/** ZIP data is inspected before writing: bounded expansion, no executable files, links, aliases or traversal. */
final readonly class ThemeArchive
{
    public function __construct(private ThemeBuildStore $builds)
    {
    }

    /**
     * Removes an installed theme's source folder (beta.17, owner: installed themes could not be removed). The
     * bundled default theme and the theme of the live build stay; published builds keep their own copies, so a
     * rollback to an older build still works.
     */
    public function remove(string $id): void
    {
        if (preg_match(ThemeChain::ID_PATTERN, $id) !== 1 || $id === 'default') {
            throw new TemplateException('theme_remove_refused');
        }
        if (($this->builds->active()['theme'] ?? 'default') === $id) {
            throw new TemplateException('theme_remove_active');
        }
        $directory = $this->builds->root . '/themes/' . $id;
        if (is_link($directory) || !is_dir($directory)) {
            throw new TemplateException('theme_remove_refused');
        }
        self::delete($directory);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new TemplateException('theme_remove_failed');
            }

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::delete($path . '/' . $name);
            }
        }
        if (!rmdir($path)) {
            throw new TemplateException('theme_remove_failed');
        }
    }

    public function import(string $zipPath): string
    {
        if (is_link($zipPath) || !is_file($zipPath) || filesize($zipPath) > 4194304) {
            throw new TemplateException('theme_package_invalid');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            throw new TemplateException('theme_package_invalid');
        }
        $files = [];
        $seen = [];
        $bytes = 0;
        try {
            if ($zip->numFiles > 300) {
                throw new TemplateException('theme_package_limit');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? '';
                ThemeFiles::assertName($name);
                $system = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) {
                    throw new TemplateException('theme_package_invalid');
                }
                $kind = ($attributes >> 16) & 0170000;
                if (
                    isset($seen[strtolower($name)]) || !in_array($kind, [0, 0100000], true)
                    || ($stat['size'] ?? 262145) > 262144 || ($stat['encryption_method'] ?? 0) !== 0
                ) {
                    throw new TemplateException('theme_package_invalid');
                }
                $seen[strtolower($name)] = true;
                $stream = $zip->getStreamIndex($index);
                if ($stream === false) {
                    throw new TemplateException('theme_package_invalid');
                }
                try {
                    $body = stream_get_contents($stream, 262145);
                } finally {
                    fclose($stream);
                }
                if (
                    !is_string($body) || strlen($body) > 262144 || strlen($body) !== $stat['size']
                    || ($bytes += strlen($body)) > 8388608 || !mb_check_encoding($body, 'UTF-8')
                ) {
                    throw new TemplateException('theme_package_limit');
                }
                $files[$name] = $body;
            }
        } finally {
            $zip->close();
        }
        $data = json_decode($files['theme.json'] ?? '', true, 16);
        $id = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : '';
        $manifest = ThemeManifest::parse($files['theme.json'] ?? '', $id);
        $installed = $this->builds->root . '/themes/' . $id;
        // An installed theme is replaced only by a newer version of itself (2026-11-17, theme updates).
        $replacing = $id !== 'default' && is_dir($installed) && !is_link($installed);
        if ($id === 'default' || is_link($installed) || (file_exists($installed) && !$replacing)) {
            throw new TemplateException('theme_identity_exists');
        }
        $newer = version_compare((string) $manifest->data['version'], self::installedVersion($installed), '>');
        if ($replacing && !$newer) {
            throw new TemplateException('theme_update_not_newer');
        }
        $tokens = isset($files['tokens.json']) ? json_decode($files['tokens.json'], true, 16) : [];
        if (!is_array($tokens)) {
            throw new TemplateException('theme_tokens_invalid');
        }
        ThemeTokens::clean($tokens);
        foreach ($manifest->data['assets'] as $asset) {
            ThemeAssetBuilder::build(
                $asset,
                $files['assets/' . $asset] ?? throw new TemplateException('theme_asset_invalid')
            );
        }
        $stage = $this->builds->directory() . '/import-' . bin2hex(random_bytes(12));
        mkdir($stage, 0700);
        try {
            $parents = ThemeChain::resolve($this->builds->root . '/themes', $manifest->data['parent'] ?? 'default');
            foreach ($parents->themes as $parent) {
                foreach (ThemeFiles::read($this->builds->root . '/themes/' . $parent) as $name => $body) {
                    self::write($stage . '/themes/' . $parent . '/' . $name, $body);
                }
            }
            foreach ($files as $name => $body) {
                self::write($stage . '/themes/' . $id . '/' . $name, $body);
            }
            $renderer = TwigFactory::create(
                $this->builds->root,
                $stage,
                TranslatorFactory::create($this->builds->root . '/translations', 'en'),
                activeTheme: $id,
                themesDirectory: $stage . '/themes'
            );
            $names = array_keys(ThemeFiles::core($this->builds->root . '/views'));
            foreach (array_keys($files) as $name) {
                if (str_starts_with($name, 'views/')) {
                    $names[] = substr($name, 6);
                }
            }
            $renderer->warm(array_values(array_unique($names)));
            // Same-filesystem installation under an exclusive lock; an existing ID is never overwritten.
            $lockPath = $this->builds->directory() . '/import.lock';
            if (is_link($lockPath)) {
                throw new TemplateException('theme_path_invalid');
            }
            $lock = fopen($lockPath, 'c+b');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new TemplateException('theme_publish_busy');
            }
            try {
                $target = $this->builds->root . '/themes/' . $id;
                if (is_link($target) || (file_exists($target) && !$replacing)) {
                    throw new TemplateException('theme_identity_exists');
                }
                // Replace: the old folder steps aside in the same directory, the new one moves in, the old one
                // is removed only after that; a failed move puts the old folder back.
                $aside = $replacing ? $target . '.old-' . bin2hex(random_bytes(6)) : null;
                if ($aside !== null && !rename($target, $aside)) {
                    throw new TemplateException('theme_path_invalid');
                }
                if (!rename($stage . '/themes/' . $id, $target)) {
                    if ($aside !== null) {
                        rename($aside, $target);
                    }
                    throw new TemplateException('theme_identity_exists');
                }
                if ($aside !== null) {
                    self::removeTree($aside);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            ThemeBuildCleanup::remove($this->builds->directory(), $stage);
        }
        return $id;
    }

    /**
     * The version in an installed theme's theme.json: '1.0.0' when it names none (the manifest default), '0' when
     * the file cannot be read, so a damaged installation can be repaired by any package.
     */
    public static function installedVersion(string $directory): string
    {
        $json = $directory . '/theme.json';
        $data = is_file($json) && !is_link($json) ? json_decode((string) file_get_contents($json), true, 16) : null;
        if (!is_array($data)) {
            return '0';
        }

        return is_string($data['version'] ?? null) ? $data['version'] : '1.0.0';
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    public function export(string $id): string
    {
        $catalog = new ThemeCatalog($this->builds->root . '/themes');
        $manifest = $catalog->manifest($id);
        $files = ThemeFiles::read($catalog->directory . '/' . $id);
        $core = ThemeFiles::core($this->builds->root . '/views');
        $data = $manifest->data;
        foreach (array_keys($files) as $name) {
            if (str_starts_with($name, 'views/')) {
                $data['upstream'][substr($name, 6)] = hash('sha256', $core[substr($name, 6)] ?? '');
            }
        }
        $files['theme.json'] = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $path = $this->builds->directory() . '/export-' . bin2hex(random_bytes(12)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new TemplateException('theme_package_invalid');
        }
        $closed = false;
        try {
            foreach ($files as $name => $body) {
                if (!$zip->addFromString($name, $body)) {
                    throw new TemplateException('theme_package_invalid');
                }
            }
            $closed = $zip->close();
            if (!$closed || filesize($path) > 4194304) {
                throw new TemplateException('theme_package_limit');
            }
            return (string) file_get_contents($path);
        } finally {
            if (!$closed) {
                try {
                    $zip->close();
                } catch (Throwable) {
                    // Preserve the original archive failure; the private temporary file is removed below.
                }
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function write(string $path, string $body): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        if (file_put_contents($path, $body) !== strlen($body)) {
            throw new TemplateException('theme_build_failed');
        }
        chmod($path, 0600);
    }
}
