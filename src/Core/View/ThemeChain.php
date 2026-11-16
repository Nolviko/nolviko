<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/**
 * The ordered list of themes a template is looked up in: active theme, its parents, then `default`.
 *
 * Each theme is `<themes>/<id>/theme.json` (`{"id": "<id>", "parent": "<id>"|null}`) with templates in
 * `views/`. Core templates (`<app>/views`) come after the chain and are added by ThemeLoader.
 */
final class ThemeChain
{
    public const DEFAULT_THEME = 'default';

    public const MANIFEST = 'theme.json';

    public const VIEWS = 'views';

    /** Lowercase ID, optionally vendor-namespaced: `default`, `acme.dark`. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}(?:\.[a-z0-9][a-z0-9_-]{0,63})?$/D';

    private const MAX_DEPTH = 8;

    /**
     * @param list<string> $themes Theme IDs, most specific first; always ends with the default theme.
     */
    private function __construct(
        public readonly string $directory,
        public readonly array $themes,
    ) {
    }

    /**
     * @throws TemplateException `theme_id_invalid`, `theme_missing`, `theme_manifest_invalid`,
     *     `theme_parent_cycle` or `theme_chain_too_deep`, each with `:<id>`.
     */
    public static function resolve(string $themesDirectory, string $active): self
    {
        $themes = [];

        for ($id = $active; $id !== null; $id = self::parent($themesDirectory, $id)) {
            if (in_array($id, $themes, true)) {
                throw new TemplateException('theme_parent_cycle:' . $id);
            }

            if (count($themes) === self::MAX_DEPTH) {
                throw new TemplateException('theme_chain_too_deep:' . $active);
            }

            $themes[] = $id;
        }

        if (!in_array(self::DEFAULT_THEME, $themes, true)) {
            if (count($themes) >= self::MAX_DEPTH) {
                throw new TemplateException('theme_chain_too_deep:' . $active);
            }
            self::parent($themesDirectory, self::DEFAULT_THEME);
            $themes[] = self::DEFAULT_THEME;
        }

        return new self($themesDirectory, $themes);
    }

    /**
     * Template directories in lookup order; a theme without `views/` is skipped.
     *
     * @return list<string>
     */
    public function viewDirectories(): array
    {
        $directories = array_map(
            fn(string $id): string => $this->directory . '/' . $id . '/' . self::VIEWS,
            $this->themes,
        );

        return array_values(array_filter($directories, is_dir(...)));
    }

    private static function parent(string $themesDirectory, string $id): ?string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new TemplateException('theme_id_invalid:' . $id);
        }

        return (new ThemeCatalog($themesDirectory))->manifest($id)->data['parent'];
    }
}
