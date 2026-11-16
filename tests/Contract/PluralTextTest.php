<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Locale\TranslatorFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * M02.13 build gate: pluralized UI text is one ICU plural message, never assembled by concatenation or by
 * choosing between singular and plural keys in code.
 */
final class PluralTextTest extends TestCase
{
    /** Concatenating a translated string in PHP (`translate(...) .` or `. ...->translate(`). */
    private const PHP_CONCATENATION = '/->translate\([^;]*?\)\s*\.\s*[\'"$]'
        . '|[\'"\w)]\s*\.\s*\$this->translator->translate\(/';

    /** Concatenating a translated string in Twig (`t(...) ~` or `~ t(`). */
    private const TWIG_CONCATENATION = '/\bt\([^)]*\)\s*~|~\s*t\(/';

    /** Picking a message by count in code (`$count === 1 ? 'x.one' : 'x.other'`). */
    private const COUNT_BRANCH = '/(?:count|total|number)\w*\s*[!=]==?\s*1\s*\?/i';

    public function testCatalogsHaveNoSingularPluralKeyPairsAndCountsUsePlural(): void
    {
        $problems = [];
        $catalogs = dirname(__DIR__, 2) . '/translations';

        foreach (TranslatorFactory::supportedLocales($catalogs) as $locale) {
            foreach (TranslatorFactory::catalogFiles($catalogs . '/' . $locale) as $file) {
                foreach (require $file as $id => $message) {
                    if (preg_match('/[._](one|other|singular|plural|many|few)$/', $id) === 1) {
                        $problems[] = $locale . ': ' . $id . ' looks like one half of a plural pair';
                    }

                    if (str_contains($message, '{count}')) {
                        $problems[] = $locale . ': ' . $id . ' prints {count} without a plural rule';
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testCodeDoesNotAssemblePluralText(): void
    {
        $problems = [];
        $app = dirname(__DIR__, 2);

        foreach (['src', 'views', 'themes'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $app . '/' . $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($files as $file) {
                $content = (string) file_get_contents($file->getPathname());
                $patterns = match ($file->getExtension()) {
                    'php' => [self::PHP_CONCATENATION, self::COUNT_BRANCH],
                    'twig' => [self::TWIG_CONCATENATION, self::COUNT_BRANCH],
                    default => [],
                };

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $content, $match) === 1) {
                        $problems[] = substr($file->getPathname(), strlen($app) + 1) . ': ' . trim($match[0]);
                    }
                }
            }
        }

        self::assertSame([], $problems, 'Use one ICU message: t(\'id\', {count: n}).');
    }

    public function testTheGateCatchesTheForbiddenShapes(): void
    {
        self::assertSame(1, preg_match(self::PHP_CONCATENATION, '$this->translator->translate(\'a.b\') . \' posts\''));
        self::assertSame(1, preg_match(self::PHP_CONCATENATION, '$n . $this->translator->translate(\'a.b\')'));
        self::assertSame(1, preg_match(self::TWIG_CONCATENATION, '{{ count ~ t("a.posts") }}'));
        self::assertSame(1, preg_match(self::COUNT_BRANCH, '$count === 1 ? \'a.post\' : \'a.posts\''));
        $allowed = '$this->translator->translate(\'a.b\', [\'count\' => $n])';

        self::assertSame(0, preg_match(self::PHP_CONCATENATION, $allowed));
    }
}
