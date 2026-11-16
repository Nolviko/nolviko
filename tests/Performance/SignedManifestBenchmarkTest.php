<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use DateTimeImmutable;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Tests\Support\ReleaseSigningFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Native Sodium verification budget, without network or database work.
 */
#[Group('benchmark')]
final class SignedManifestBenchmarkTest extends TestCase
{
    public function testVerificationBudget(): void
    {
        $body = ReleaseSigningFixture::body();
        $signature = ReleaseSigningFixture::sign($body);
        $keys = ['test-key' => ReleaseSigningFixture::key()];
        $now = new DateTimeImmutable('@' . ReleaseSigningFixture::NOW);
        $report = BenchmarkHarness::measure(
            static fn(): SignedReleaseManifest => SignedReleaseManifest::verify(
                $body,
                $signature,
                $keys,
                ReleaseSigningFixture::RELEASE,
                'stable',
                1,
                $now,
            ),
            100,
            10,
        );
        self::assertSame(100, $report['samples']);
        fwrite(STDERR, 'signed_manifest_verify ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
    }
}
