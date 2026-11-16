<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Routing;

use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\ReleaseId;
use Nolviko\Core\Routing\RouteCache;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Tests\Fixtures\ForumRoutes;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RouteCacheTest extends TestCase
{
    private TemporaryDirectory $temporary;

    protected function setUp(): void
    {
        $this->temporary = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->temporary->remove();
    }

    public function testCompilesOnceAndThenServesTheCachedRelease(): void
    {
        $cache = new RouteCache($this->directory());

        $first = $cache->load('r1', ForumRoutes::definitions());
        $second = $cache->load('r1', self::otherRoutes());

        self::assertFileExists($this->directory() . '/routes-r1.php');
        self::assertEquals($first, $second, 'same release must be served from the cache file');
        self::assertArrayHasKey('thread.show', $second->generator);
    }

    public function testReleaseChangeRebuildsAndRemovesOldReleaseFiles(): void
    {
        $cache = new RouteCache($this->directory());
        $cache->load('r1', ForumRoutes::definitions());

        $next = $cache->load('r2', self::otherRoutes());

        self::assertArrayHasKey('other', $next->generator);
        self::assertSame([$this->directory() . '/routes-r2.php'], glob($this->directory() . '/routes-*.php'));
    }

    public function testCorruptCacheFileIsRecompiled(): void
    {
        $cache = new RouteCache($this->directory());
        $this->temporary->write('cache/routes/routes-r1.php', '<?php return [');

        $compiled = $cache->load('r1', ForumRoutes::definitions());

        self::assertArrayHasKey('thread.show', $compiled->generator);
        self::assertEquals(
            $compiled,
            CompiledRoutes::fromExport(require $this->directory() . '/routes-r1.php', 'r1'),
        );
    }

    public function testForeignReleaseOrFormatInFileIsRecompiled(): void
    {
        $this->temporary->write(
            'cache/routes/routes-r1.php',
            CompiledRoutes::compile(self::otherRoutes())->export('r0'),
        );

        $compiled = (new RouteCache($this->directory()))->load('r1', ForumRoutes::definitions());

        self::assertArrayHasKey('thread.show', $compiled->generator);
    }

    public function testWithoutUsableDirectoryRoutesCompileInMemory(): void
    {
        $blocked = $this->temporary->write('blocked', 'not a directory');

        $none = (new RouteCache(null))->load('r1', ForumRoutes::definitions());
        $unwritable = (new RouteCache($blocked . '/routes'))->load('r1', ForumRoutes::definitions());

        self::assertArrayHasKey('thread.show', $none->generator);
        self::assertArrayHasKey('thread.show', $unwritable->generator);
        self::assertNull((new RouteCache(null))->file('r1'));
        self::assertNull((new RouteCache($this->directory()))->file('../escape'));
    }

    public function testReleaseIdComesFromTheReleaseFile(): void
    {
        $file = $this->temporary->write('release.php', "<?php return 'Nolviko-20261031-120000-abc123';");

        self::assertSame('Nolviko-20261031-120000-abc123', ReleaseId::fromFile($file));
        self::assertNull(ReleaseId::fromFile($this->temporary->path . '/missing.php'));
    }

    public function testInvalidReleaseFileIsRejected(): void
    {
        $file = $this->temporary->write('release.php', "<?php return '../../x';");

        $this->expectException(UnexpectedValueException::class);

        ReleaseId::fromFile($file);
    }

    public function testDevelopmentReleaseIdChangesWithTheRoutes(): void
    {
        $id = ReleaseId::development(ForumRoutes::definitions());

        self::assertMatchesRegularExpression(ReleaseId::PATTERN, $id);
        self::assertSame($id, ReleaseId::development(ForumRoutes::definitions()));
        self::assertNotSame($id, ReleaseId::development(self::otherRoutes()));
    }

    private function directory(): string
    {
        return $this->temporary->path . '/cache/routes';
    }

    /**
     * @return list<RouteDefinition>
     */
    private static function otherRoutes(): array
    {
        return [new RouteDefinition('other', '/other', ['GET'])];
    }
}
