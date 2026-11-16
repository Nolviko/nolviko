<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The layout rules of docs/CODING_STYLE.md that PHPCS does not check, for every maintained text file in app/:
 * UTF-8 without BOM, LF only, no tabs, no trailing whitespace, exactly one final newline; and for PHP, one
 * sorted `use` block and only final, abstract or readonly-final classes.
 */
final class CodingStyleTest extends TestCase
{
    private const DIRECTORIES = ['src', 'tests', 'tools', 'config', 'translations', 'views', 'themes', 'public',
        'docker'];

    private const EXTENSIONS = ['php', 'twig', 'json', 'txt', 'md', 'sh', 'conf', 'ini', 'yaml', 'yml'];

    public function testTextFilesHaveCanonicalWhitespace(): void
    {
        $problems = [];

        foreach (self::files() as $relative => $content) {
            $checks = [
                'BOM' => str_starts_with($content, "\xEF\xBB\xBF"),
                'CR line ending' => str_contains($content, "\r"),
                'tab' => str_contains($content, "\t"),
                'trailing whitespace' => preg_match('/[ \t]+$/m', $content) === 1,
                'missing final newline' => $content !== '' && !str_ends_with($content, "\n"),
                'extra final newline' => str_ends_with($content, "\n\n"),
                'invalid UTF-8' => !mb_check_encoding($content, 'UTF-8'),
            ];

            foreach (array_keys(array_filter($checks)) as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    public function testPhpImportsAreOneSortedBlock(): void
    {
        $problems = [];

        foreach (self::files('php') as $relative => $content) {
            preg_match_all('/^use [^\n]+;$/m', $content, $matches, PREG_OFFSET_CAPTURE);
            $imports = array_column($matches[0], 0);
            $sorted = $imports;
            usort($sorted, self::compareImports(...));

            if ($imports !== $sorted) {
                $problems[] = $relative . ': use statements are not sorted';
            }

            if (preg_match('/^use [^\n]+;\n\n+use /m', $content) === 1) {
                $problems[] = $relative . ': use statements are split into several blocks';
            }
        }

        self::assertSame([], $problems);
    }

    public function testClassesAreFinalUnlessAbstract(): void
    {
        $problems = [];

        foreach (self::files('php') as $relative => $content) {
            if (preg_match('/^(?!final |abstract )(?:readonly )?class \w+/m', $content) === 1) {
                $problems[] = $relative;
            }
        }

        self::assertSame([], $problems, 'Declare classes final (or abstract).');
    }

    /**
     * Case-insensitive, namespace segment by segment, so `Foo\Bar` sorts before `FooBar`.
     */
    private static function compareImports(string $a, string $b): int
    {
        $left = explode('\\', strtolower(substr($a, 4, -1)));
        $right = explode('\\', strtolower(substr($b, 4, -1)));

        // Not `$left <=> $right`: PHP compares arrays by length first.
        foreach ($left as $index => $segment) {
            if (!isset($right[$index])) {
                return 1;
            }

            if ($segment !== $right[$index]) {
                return strcmp($segment, $right[$index]);
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @return array<string, string> Path relative to app/ => content.
     */
    private static function files(?string $extension = null): array
    {
        $app = dirname(__DIR__, 2);
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            if (!is_dir($app . '/' . $directory)) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $app . '/' . $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($entries as $entry) {
                $type = $entry->getExtension();

                if ($entry->isFile() && in_array($type, $extension === null ? self::EXTENSIONS : [$extension], true)) {
                    $relative = substr(strtr($entry->getPathname(), '\\', '/'), strlen($app) + 1);
                    $files[$relative] = (string) file_get_contents($entry->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }
}
