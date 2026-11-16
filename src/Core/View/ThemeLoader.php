<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Twig's filesystem loader with the theme fallback order and stricter names.
 *
 * Native namespaces: the main namespace searches the theme chain and then the core views, so an override
 * wins and anything missing falls back; `@core/...` is the core template only, so an override can extend
 * the template it replaces. Names are lowercase relative `.twig` paths without `..`, backslashes or
 * absolute paths, and the resolved file (symlinks followed) must stay inside the themes or core directory.
 */
final class ThemeLoader extends FilesystemLoader
{
    public const CORE_NAMESPACE = 'core';

    private const NAME_PATTERN = '~^(?:@[a-z][a-z0-9_]*/)?[a-z0-9_][a-z0-9_.-]*(?:/[a-z0-9_][a-z0-9_.-]*)*\.twig$~D';

    /** @var list<string> */
    private readonly array $roots;

    public function __construct(ThemeChain $chain, string $coreViews)
    {
        parent::__construct();
        $this->roots = array_values(array_filter([self::root($chain->directory), self::root($coreViews)]));

        foreach ($chain->viewDirectories() as $directory) {
            $this->addPath($directory);
        }

        $this->addPath($coreViews);
        $this->addPath($coreViews, self::CORE_NAMESPACE);
    }

    protected function findTemplate(string $name, bool $throw = true): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || str_contains($name, '..')) {
            return self::fail('template_name_invalid:' . $name, $throw);
        }

        $file = parent::findTemplate($name, $throw);

        if ($file === null) {
            return null;
        }

        $real = realpath($file);

        foreach ($this->roots as $root) {
            if ($real !== false && str_starts_with($real, $root)) {
                return $file;
            }
        }

        return self::fail('template_outside_roots:' . $name, $throw);
    }

    private static function fail(string $diagnostic, bool $throw): ?string
    {
        if ($throw) {
            throw new LoaderError($diagnostic);
        }

        return null;
    }

    private static function root(string $directory): ?string
    {
        $real = realpath($directory);

        return $real === false ? null : rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }
}
