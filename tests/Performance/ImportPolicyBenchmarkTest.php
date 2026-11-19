<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\ReleaseVersion;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('benchmark')]
final class ImportPolicyBenchmarkTest extends TestCase
{
    public function testBoundedLegacyTextAndReleaseOrdering(): void
    {
        $report = BenchmarkHarness::measure(static function (): void {
            ImportCursor::parts(ImportCursor::key([PHP_INT_MAX, 42, 0]), 3);
            (new ReleaseVersion('1.10.0'))->newerThan(new ReleaseVersion('1.9.9'));
        }, 100, 100);
        fwrite(STDERR, 'import_cursor_semver ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        self::assertTrue((new ReleaseVersion('1.10.0'))->newerThan(new ReleaseVersion('1.9.9')));
    }
}
