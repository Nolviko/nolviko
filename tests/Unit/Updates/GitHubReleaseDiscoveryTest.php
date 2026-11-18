<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use DateTimeImmutable;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Updates\GitHubReleaseDiscovery;
use Nolviko\Core\Updates\NativeReleaseHttpClient;
use Nolviko\Core\Updates\ReleaseDiscoveryResult;
use Nolviko\Core\Updates\ReleaseHttpResponse;
use Nolviko\Core\Updates\ReleaseRepository;
use Nolviko\Tests\Support\MemoryReleaseHttpClient;
use Nolviko\Tests\Support\RecordingLogger;
use Nolviko\Tests\Support\ReleaseDiscoveryFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Conditional discovery, last-good fallback, rate-limit scheduling and untrusted metadata boundaries.
 */
final class GitHubReleaseDiscoveryTest extends TestCase
{
    private TemporaryDirectory $root;

    private FileCache $cache;

    private ReleaseRepository $repository;

    private RecordingLogger $logger;

    private int $timestamp = ReleaseDiscoveryFixture::NOW;

    protected function setUp(): void
    {
        $this->root = new TemporaryDirectory();
        $this->cache = new FileCache($this->root->path . '/cache', $this->root->path, fn(): int => $this->timestamp);
        $this->repository = new ReleaseRepository('example', 'forum');
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    public function testFreshCatalogUsesUploadedAssetsAndDeterministicOrdering(): void
    {
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body([
            ReleaseDiscoveryFixture::release(1),
            ReleaseDiscoveryFixture::release(2, ['prerelease' => true]),
            ReleaseDiscoveryFixture::release(3),
            ReleaseDiscoveryFixture::release(4, ['draft' => true, 'published_at' => null]),
        ]), '"etag-1"')]);
        $result = $this->check(new GitHubReleaseDiscovery($client, $this->cache, $this->logger));
        self::assertSame('fresh', $result->status);
        self::assertSame([3, 1], array_column($result->releases, 'id'));
        self::assertSame(30, $result->releases[0]->assets[0]['id']);
        self::assertSame(['id', 'name', 'bytes'], array_keys($result->releases[0]->assets[0]));
        self::assertStringNotContainsString('untrusted.invalid', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            'https://api.github.com/repos/example/forum/releases?per_page=100',
            $client->requests[0]['url'],
        );
        self::assertNull($client->requests[0]['etag']);
        self::assertFalse($result->truncated);
    }

    public function testChannelsShareTheCacheButKeepSeparateCatalogs(): void
    {
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body([
            ReleaseDiscoveryFixture::release(1),
            ReleaseDiscoveryFixture::release(2, ['prerelease' => true]),
        ]))]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        self::assertSame([1], array_column($this->check($service)->releases, 'id'));
        $preview = $this->check($service, 'prerelease');
        self::assertSame('cached', $preview->status);
        self::assertSame([2, 1], array_column($preview->releases, 'id'), 'betas also see stable releases');
        self::assertCount(1, $client->requests);
    }

    public function testNotModifiedRetainsTheLastCatalogAndSendsTheEtag(): void
    {
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body([ReleaseDiscoveryFixture::release()]), '"v1"'),
            new ReleaseHttpResponse(304, '', '"v1"'),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $this->check($service);
        $this->timestamp += GitHubReleaseDiscovery::CHECK_INTERVAL + 1;
        $result = $this->check($service);
        self::assertSame('not_modified', $result->status);
        self::assertSame('"v1"', $client->requests[1]['etag']);
        self::assertSame([1], array_column($result->releases, 'id'));
        self::assertSame($this->timestamp, $result->lastSuccessAt);
    }

    public function testNewResponseWithoutEtagDoesNotReuseThePreviousValidator(): void
    {
        $body = ReleaseDiscoveryFixture::body([ReleaseDiscoveryFixture::release()]);
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(200, $body, '"old"'),
            new ReleaseHttpResponse(200, $body),
            new ReleaseHttpResponse(200, $body),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $this->check($service);
        $this->check($service, force: true);
        $this->check($service, force: true);
        self::assertSame('"old"', $client->requests[1]['etag']);
        self::assertNull($client->requests[2]['etag']);
    }

    public function testNetworkFailureKeepsLastGoodDataAndDoesNotLogExceptionText(): void
    {
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body([ReleaseDiscoveryFixture::release()])),
            new RuntimeException('private-token-secret transport detail'),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $first = $this->check($service);
        $this->timestamp += GitHubReleaseDiscovery::CHECK_INTERVAL + 1;
        $result = $this->check($service);
        self::assertSame('stale', $result->status);
        self::assertSame([1], array_column($result->releases, 'id'));
        self::assertSame($first->lastSuccessAt, $result->lastSuccessAt);
        self::assertSame('github_release_transport_failed', $result->diagnostic);
        self::assertStringNotContainsString('private-token-secret', json_encode($this->logger->records));
        $this->check($service, force: true);
        self::assertCount(2, $client->requests, 'force must not override error backoff');
    }

    public function testServerRetryAndRateLimitResetAreHonored(): void
    {
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(429, 'error', retryAfter: 7200, rateLimitReset: $this->timestamp + 8000),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $result = $this->check($service);
        self::assertSame('unavailable', $result->status);
        self::assertSame($this->timestamp + 8000, $result->nextCheckAt);
        $this->check($service, force: true);
        self::assertCount(1, $client->requests);
    }

    public function testRepeatedFailuresIncreaseBackoff(): void
    {
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(503, ''),
            new ReleaseHttpResponse(503, ''),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $first = $this->check($service);
        self::assertSame($this->timestamp + 300, $first->nextCheckAt);
        $this->timestamp = $first->nextCheckAt;
        $next = $this->check($service);
        self::assertSame($this->timestamp + 600, $next->nextCheckAt);
    }

    public function testNotModifiedWithoutACachedBodyFailsClosed(): void
    {
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(304, '')]);
        $result = $this->check(new GitHubReleaseDiscovery($client, $this->cache, $this->logger));
        self::assertSame('unavailable', $result->status);
        self::assertSame('github_release_cache_missing', $result->diagnostic);
    }

    public function testMalformedReplacementCannotOverwriteLastGoodCatalog(): void
    {
        $client = new MemoryReleaseHttpClient([
            new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body([ReleaseDiscoveryFixture::release()])),
            new ReleaseHttpResponse(200, '{'),
        ]);
        $service = new GitHubReleaseDiscovery($client, $this->cache, $this->logger);
        $this->check($service);
        $result = $this->check($service, force: true);
        self::assertSame('stale', $result->status);
        self::assertSame([1], array_column($result->releases, 'id'));
        self::assertSame('github_release_response_invalid', $result->diagnostic);
    }

    public function testFullPageIsMarkedTruncatedEvenWhenAllRowsAreDrafts(): void
    {
        $rows = [];

        for ($index = 1; $index <= GitHubReleaseDiscovery::MAX_RELEASES; ++$index) {
            $rows[] = ReleaseDiscoveryFixture::release($index, ['draft' => true]);
        }

        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, ReleaseDiscoveryFixture::body($rows))]);
        $result = $this->check(new GitHubReleaseDiscovery($client, $this->cache, $this->logger));
        self::assertSame([], $result->releases);
        self::assertTrue($result->truncated);
    }

    public function testUnavailableCacheStillKeepsInProcessBackoff(): void
    {
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(503, '')]);
        $service = new GitHubReleaseDiscovery($client, new FileCache(null), $this->logger);
        self::assertSame('unavailable', $this->check($service)->status);
        self::assertSame('unavailable', $this->check($service, force: true)->status);
        self::assertCount(1, $client->requests);
    }

    public function testBadCacheShapeIsIgnored(): void
    {
        $this->cache->set($this->repository->key(), ['format' => 1, 'body' => '{']);
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, '[]')]);
        self::assertSame('fresh', $this->check(new GitHubReleaseDiscovery(
            $client,
            $this->cache,
            $this->logger,
        ))->status);
    }

    #[DataProvider('invalidBodies')]
    public function testUntrustedApiDataCannotBecomeAUsableCatalog(string $body): void
    {
        $client = new MemoryReleaseHttpClient([new ReleaseHttpResponse(200, $body)]);
        $result = $this->check(new GitHubReleaseDiscovery($client, $this->cache, $this->logger));
        self::assertSame('unavailable', $result->status);
        self::assertSame([], $result->releases);
        self::assertSame('github_release_response_invalid', $result->diagnostic);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'malformed JSON' => ['{'],
            'object root' => ['{}'],
            'oversized body' => [str_repeat(' ', NativeReleaseHttpClient::MAX_BODY_BYTES + 1)],
            'string id' => [ReleaseDiscoveryFixture::body([ReleaseDiscoveryFixture::release(1, ['id' => '1'])])],
            'invalid draft flag' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(1, ['draft' => 'false']),
            ])],
            'invalid date' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(1, ['published_at' => '2026-02-31T12:00:00Z']),
            ])],
            'control tag' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(1, ['tag_name' => "v1\nspoof"]),
            ])],
            'missing assets' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(1, ['assets' => null]),
            ])],
            'duplicate ids' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(),
                ReleaseDiscoveryFixture::release(),
            ])],
            'ambiguous asset names' => [ReleaseDiscoveryFixture::body([
                ReleaseDiscoveryFixture::release(1, ['assets' => [
                    ['id' => 1, 'name' => 'Release.zip', 'size' => 1, 'state' => 'uploaded'],
                    ['id' => 2, 'name' => 'release.zip', 'size' => 1, 'state' => 'uploaded'],
                ]]),
            ])],
        ];
    }

    private function check(
        GitHubReleaseDiscovery $service,
        string $channel = 'stable',
        bool $force = false,
    ): ReleaseDiscoveryResult {
        return $service->check($this->repository, $channel, new DateTimeImmutable('@' . $this->timestamp), $force);
    }
}
