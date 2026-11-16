<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use DateTimeImmutable;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Updates\GitHubReleaseDiscovery;
use Nolviko\Core\Updates\ReleaseDiscoveryResult;
use Nolviko\Core\Updates\ReleaseHttpResponse;
use Nolviko\Core\Updates\ReleaseRepository;
use Nolviko\Tests\Support\MemoryReleaseHttpClient;
use Nolviko\Tests\Support\RecordingLogger;
use Nolviko\Tests\Support\ReleaseDiscoveryFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cached release discovery reparses bounded data and must not poll the network on each invocation.
 */
#[Group('benchmark')]
final class ReleaseDiscoveryBenchmarkTest extends TestCase
{
    public function testCachedCatalogCostAndNetworkBudget(): void
    {
        $root = new TemporaryDirectory();
        $cache = new FileCache($root->path . '/cache', $root->path, static fn(): int => ReleaseDiscoveryFixture::NOW);
        $rows = [];

        foreach (range(1, 20) as $id) {
            $rows[] = ReleaseDiscoveryFixture::release($id);
        }

        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body($rows))]);
        $service = new GitHubReleaseDiscovery($client, $cache, new RecordingLogger());
        $repository = new ReleaseRepository('example', 'forum');
        $now = new DateTimeImmutable('@' . ReleaseDiscoveryFixture::NOW);
        $service->check($repository, 'stable', $now);

        try {
            $report = BenchmarkHarness::measure(
                static fn(): ReleaseDiscoveryResult => $service->check($repository, 'stable', $now),
                100,
                10,
            );
            self::assertCount(1, $client->requests);
            self::assertSame('cached', $service->check($repository, 'stable', $now)->status);
            fwrite(STDERR, 'release_discovery_cached ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $root->remove();
        }
    }
}
