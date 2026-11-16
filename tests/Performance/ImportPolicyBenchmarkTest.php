<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\ReleaseVersion;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportText;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('benchmark')]
final class ImportPolicyBenchmarkTest extends TestCase
{
    public function testBoundedLegacyTextAndReleaseOrdering(): void
    {
        $source = '<r>' . str_repeat('<B><s>[b]</s>Çığ<e>[/b]</e></B> ', 500) . '</r>';
        for ($run = 0; $run < 3; ++$run) {
            $report = BenchmarkHarness::measure(
                static fn(): string => ImportText::body($source, 'phpbb', 'UTF-8'),
                100,
                100
            );
            fwrite(STDERR, 'legacy_xml_normalization ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        }
        self::assertLessThan(50000, mb_strlen(ImportText::body($source, 'phpbb', 'UTF-8'), 'UTF-8'));
        $report = BenchmarkHarness::measure(static function (): void {
            ImportCursor::parts(ImportCursor::key([PHP_INT_MAX, 42, 0]), 3);
            (new ReleaseVersion('1.10.0'))->newerThan(new ReleaseVersion('1.9.9'));
        }, 100, 100);
        fwrite(STDERR, 'import_cursor_semver ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        self::assertTrue((new ReleaseVersion('1.10.0'))->newerThan(new ReleaseVersion('1.9.9')));
    }
}
