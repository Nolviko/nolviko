<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Tools;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tools\I18n\Finding;
use Nolviko\Tools\I18n\LiteralScanner;
use Nolviko\Tools\I18n\TranslationKeyScanner;
use PHPUnit\Framework\TestCase;

final class I18nScannerTest extends TestCase
{
    private TemporaryDirectory $scratch;

    protected function setUp(): void
    {
        $this->scratch = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->scratch->remove();
    }

    public function testPhpSentenceLiteralsAreReported(): void
    {
        $this->scratch->write('src/Sample.php', implode("\n", [
            '<?php',
            "throw new RuntimeException('Something broke badly');",
            "\$sql = 'SELECT id FROM threads WHERE id = :id';",
            "\$diagnostic = 'php_version_unsupported';",
            "\$brand = 'Nolviko';",
            "\$header = \"frame-ancestors 'none'\";",
            "\$reviewed = 'Reviewed exception text'; // i18n-ignore",
            '$interpolated = "Hello $name, welcome";',
        ]));

        self::assertSame(
            ['2 Something broke badly', '8 Hello '],
            self::summaries((new LiteralScanner())->scan([$this->scratch->path])),
        );
    }

    public function testTwigVisibleTextIsReported(): void
    {
        $this->scratch->write('templates/page.twig', implode("\n", [
            '<h1>Welcome back</h1>',
            "<h2>{{ t('core.error.server.title') }}</h2>",
            '{# a comment with words #}',
            '<img src="/logo.svg" alt="Logo of the site">',
            "<input placeholder=\"{{ t('core.error.not_found.title') }}\">",
            '<script>const answer = 42;</script>',
            '<p>Nolviko</p>',
        ]));

        self::assertSame(
            ['1 Welcome back', '4 Logo of the site'],
            self::summaries((new LiteralScanner())->scan([$this->scratch->path])),
        );
    }

    public function testMissingSourceDirectoriesAreSkipped(): void
    {
        self::assertSame([], (new LiteralScanner())->scan([$this->scratch->path . '/absent']));
    }

    public function testUsedMessageIdsMissingFromEnglishAreReported(): void
    {
        $this->scratch->write('src/Handler.php', implode("\n", [
            '<?php',
            "\$title = \$translator->translate('core.error.server.title');",
            "\$body = \$translator->translate('core.error.typo');",
        ]));
        $this->scratch->write('templates/page.twig', "<p>\n{{ t('core.missing.key', {count: 1}) }}</p>");
        $translator = TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', 'en');
        $scanner = new TranslationKeyScanner();

        self::assertCount(3, $scanner->usages([$this->scratch->path]));
        self::assertSame(
            ['3 core.error.typo', '2 core.missing.key'],
            self::summaries($scanner->missing([$this->scratch->path], $translator)),
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string> "line text" pairs, without the scratch path.
     */
    private static function summaries(array $findings): array
    {
        return array_map(static fn(Finding $finding): string => $finding->line . ' ' . $finding->text, $findings);
    }
}
