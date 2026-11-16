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
        if (
            $id === 'default' || file_exists($this->builds->root . '/themes/' . $id)
            || is_link($this->builds->root . '/themes/' . $id)
        ) {
            throw new TemplateException('theme_identity_exists');
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
                if (file_exists($target) || is_link($target) || !rename($stage . '/themes/' . $id, $target)) {
                    throw new TemplateException('theme_identity_exists');
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
