<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Core\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Source;

/**
 * M05.05 lint over every shipped template (core views and themes): each compiles under the production policy
 * and has no raw output, no disabled escaping and no unquoted `{{ }}` attribute value.
 */
final class TemplateLintTest extends TestCase
{
    public function testShippedTemplatesFollowThePolicy(): void
    {
        $app = dirname(__DIR__, 2);
        $files = [...self::templates($app . '/views'), ...self::templates($app . '/themes')];
        $names = array_map(
            static fn(string $file): string => (string) preg_replace('~^.*?/views/~', '', strtr($file, '\\', '/')),
            $files,
        );
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $twig = self::environment(TwigFactory::create($app, $app . '/no-storage', $translator));
        // Themes may add templates core does not have (2026-11-15, e.g. lumen/hero.twig); Twig asks the loader
        // for every compiled name, so the theme view folders are searched after core.
        $themeViews = array_values(array_filter(glob($app . '/themes/*/views') ?: [], is_dir(...)));
        $twig->setLoader(new ChainLoader([$twig->getLoader(), new FilesystemLoader($themeViews)]));

        self::assertNotEmpty($files);

        foreach ($files as $index => $file) {
            $source = (string) file_get_contents($file);
            $twig->compileSource(new Source($source, $names[$index], $file));

            self::assertDoesNotMatchRegularExpression('/\|\s*raw\b|autoescape\s+false/', $source, $file);
            self::assertDoesNotMatchRegularExpression('/=\s*\{\{/', $source, $file . ': quote attribute values');
        }
    }

    private static function environment(TwigRenderer $renderer): Environment
    {
        return (fn(): Environment => $this->twig)->call($renderer);
    }

    /**
     * @return list<string>
     */
    private static function templates(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.twig')) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
