<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Updates\GitHubReleaseDiscovery;
use Nolviko\Core\Updates\PackageUpdates;
use Nolviko\Core\Updates\ReleaseHttpClient;
use Nolviko\Core\Updates\ReleaseHttpResponse;
use Nolviko\Core\Updates\ReleaseRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/** Panel update checks for extensions and themes (2026-11-17). */
final class PackageUpdatesTest extends TestCase
{
    private TemporaryDirectory $files;

    protected function setUp(): void
    {
        $this->files = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testPicksTheNewestStableReleaseThatCarriesTheExpectedPackage(): void
    {
        $updates = $this->updates([
            self::release(1, 'v1.0.2', ['nolviko-ext-nolviko-totp-1.0.2.zip']),
            self::release(2, 'v1.0.3', ['nolviko-ext-nolviko-totp-1.0.3.zip']),
            self::release(3, 'v1.0.9', ['something-else.zip']),
            self::release(4, 'v1.1.0', ['nolviko-ext-nolviko-totp-1.1.0.zip'], prerelease: true),
        ]);

        self::assertSame(
            ['repository' => 'Nolviko/extension-totp', 'version' => '1.0.3', 'tag' => 'v1.0.3',
                'asset' => 'nolviko-ext-nolviko-totp-1.0.3.zip', 'bytes' => 1234],
            $updates->available('Nolviko/extension-totp', '1.0.2', PackageUpdates::extensionAsset('nolviko/totp')),
        );
        self::assertNull(
            $updates->available('Nolviko/extension-totp', '1.0.3', PackageUpdates::extensionAsset('nolviko/totp')),
            'nothing newer than what is installed',
        );
    }

    public function testInvalidRepositoriesAndNetworkFailuresShowNoUpdate(): void
    {
        $updates = $this->updates([self::release(1, 'v2.0.0', ['nolviko-theme-lumen-2.0.0.zip'])]);
        foreach (['', 'no-slash', 'a/b/c', 'https://github.com/x/y', '../x/y'] as $spec) {
            self::assertNull($updates->available($spec, '1.0.0', PackageUpdates::themeAsset('lumen')), $spec);
        }

        $offline = new PackageUpdates(new GitHubReleaseDiscovery(
            new class implements ReleaseHttpClient {
                public function fetch(ReleaseRepository $repository, ?string $etag): ReleaseHttpResponse
                {
                    throw new RuntimeException('github_transport_failed');
                }
            },
            (new CacheFactory($this->files->path))->namespace('discovery'),
            new NullLogger(),
        ));
        self::assertNull($offline->available('Nolviko/theme-lumen', '1.0.0', PackageUpdates::themeAsset('lumen')));
    }

    /** @param list<array<string, mixed>> $releases */
    private function updates(array $releases): PackageUpdates
    {
        $body = json_encode($releases, JSON_THROW_ON_ERROR);

        return new PackageUpdates(new GitHubReleaseDiscovery(
            new class ($body) implements ReleaseHttpClient {
                public function __construct(private readonly string $body)
                {
                }

                public function fetch(ReleaseRepository $repository, ?string $etag): ReleaseHttpResponse
                {
                    return new ReleaseHttpResponse(200, $this->body);
                }
            },
            (new CacheFactory($this->files->path))->namespace('discovery'),
            new NullLogger(),
        ));
    }

    /**
     * @param list<string> $assets
     * @return array<string, mixed>
     */
    private static function release(int $id, string $tag, array $assets, bool $prerelease = false): array
    {
        return ['id' => $id, 'tag_name' => $tag, 'draft' => false, 'prerelease' => $prerelease,
            'published_at' => '2026-11-17T06:00:0' . $id . 'Z',
            'assets' => array_map(
                static fn(string $name, int $n): array => ['id' => $id * 10 + $n, 'name' => $name,
                    'state' => 'uploaded', 'size' => 1234],
                $assets,
                array_keys($assets),
            )];
    }
}
