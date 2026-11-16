<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/** Discovery is limited to direct, non-symlink theme directories; IDs are bound to their directory name. */
final readonly class ThemeCatalog
{
    public function __construct(public string $directory)
    {
    }

    public function manifest(string $id): ThemeManifest
    {
        if (preg_match(ThemeChain::ID_PATTERN, $id) !== 1) {
            throw new TemplateException('theme_id_invalid:' . $id);
        }
        $folder = $this->directory . '/' . $id;
        $file = $folder . '/theme.json';
        if (is_link($folder) || is_link($file) || !is_file($file)) {
            throw new TemplateException('theme_missing:' . $id);
        }
        self::contained($this->directory, $file);
        if (filesize($file) > 65536) {
            throw new TemplateException('theme_manifest_invalid:' . $id);
        }
        return ThemeManifest::parse((string) file_get_contents($file), $id);
    }

    public function all(): array
    {
        $result = [];
        foreach (scandir($this->directory) ?: [] as $id) {
            if (preg_match(ThemeChain::ID_PATTERN, $id) !== 1 || !is_dir($this->directory . '/' . $id)) {
                continue;
            }
            if (count($result) >= 100) {
                throw new TemplateException('theme_catalog_limit');
            }
            try {
                $result[] = ['id' => $id, 'version' => $this->manifest($id)->data['version'], 'valid' => true];
            } catch (TemplateException) {
                $result[] = ['id' => $id, 'version' => '', 'valid' => false];
            }
        }
        return $result;
    }

    public static function contained(string $root, string $file): string
    {
        $base = realpath($root);
        $path = realpath($file);
        if ($base === false || $path === false || !str_starts_with($path, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)) {
            throw new TemplateException('theme_path_invalid');
        }
        return $path;
    }
}
