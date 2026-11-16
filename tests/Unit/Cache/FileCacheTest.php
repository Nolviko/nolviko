<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Cache;

use ArrayObject;
use InvalidArgumentException;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M01.10 single-process behaviour; the multi-process checks are in Contract\FileCacheConcurrencyTest.
 */
final class FileCacheTest extends TestCase
{
    private TemporaryDirectory $storage;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->storage = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->storage->remove();
    }

    public function testValuesRoundTripIncludingNullAndFalse(): void
    {
        $cache = $this->cache();
        $values = ['s' => 'text ü', 'i' => 42, 'f' => 1.5, 'b' => false, 'n' => null, 'a' => ['x' => [1, 'y']]];

        foreach ($values as $key => $value) {
            self::assertTrue($cache->set($key, $value));
        }

        foreach ($values as $key => $value) {
            self::assertSame($value, $cache->get($key, 'default'));
            self::assertTrue($cache->has($key));
        }

        self::assertSame('default', $cache->get('missing', 'default'));
        self::assertFalse($cache->has('missing'));
        self::assertTrue($cache->delete('s'));
        self::assertTrue($cache->delete('s'), 'deleting a missing key succeeds');
        self::assertNull($cache->get('s'));
    }

    public function testTtlExpiresAndZeroDeletes(): void
    {
        $cache = $this->cache();
        $cache->set('short', 'v', 10);
        $cache->set('forever', 'v');

        $this->now += 9;
        self::assertSame('v', $cache->get('short'));

        $this->now += 1;
        self::assertNull($cache->get('short'));
        self::assertFalse($cache->has('short'));
        self::assertSame('v', $cache->get('forever'));

        $cache->set('forever', 'v', 0);
        self::assertFalse($cache->has('forever'));
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function corruptions(): array
    {
        return [
            'truncated' => [static fn(string $content): string => substr($content, 0, -3)],
            'empty' => [static fn(string $content): string => ''],
            'flipped byte' => [static fn(string $content): string => substr($content, 0, -1) . 'X'],
            'foreign header' => [static fn(string $content): string => 'OTHER' . substr($content, 8)],
            'forged object' => [static function (string $content): string {
                $payload = serialize(new ArrayObject([1]));

                return 'FECACHE1 0 ' . hash('sha256', $payload) . "\n" . $payload;
            }],
        ];
    }

    /**
     * @param callable(string): string $corrupt
     */
    #[DataProvider('corruptions')]
    public function testCorruptFileIsAMissAndIsRemoved(callable $corrupt): void
    {
        $cache = $this->cache();
        $cache->set('k', ['value' => 1]);
        $file = (string) current(glob($this->namespaceDirectory() . '/*/*.cache') ?: []);
        file_put_contents($file, $corrupt((string) file_get_contents($file)));

        self::assertSame('miss', $cache->get('k', 'miss'));
        self::assertFileDoesNotExist($file);
        self::assertSame(2, $cache->remember('k', null, static fn(): int => 2));
    }

    public function testObjectsAndBadKeysAreRefused(): void
    {
        $cache = $this->cache();

        foreach (['', str_repeat('k', 201), 'a:b', 'a/b', 'a{b}', 'a@b', 'a\\b'] as $key) {
            try {
                $cache->get($key);
                self::fail('accepted key ' . $key);
            } catch (InvalidArgumentException $failure) {
                self::assertSame('cache_key_invalid', $failure->getMessage());
            }
        }

        $this->expectExceptionMessage('cache_value_unsupported');
        $cache->set('k', ['nested' => [new ArrayObject()]]);
    }

    public function testClearRemovesOnlyItsNamespace(): void
    {
        $factory = new CacheFactory($this->storage->path);
        $one = $factory->namespace('one');
        $two = $factory->namespace('two');
        $one->set('k', 1);
        $two->set('k', 2);

        self::assertTrue($one->clear());
        self::assertNull($one->get('k'));
        self::assertSame(2, $two->get('k'));
        self::assertTrue($one->set('k', 3), 'the namespace is usable again');
        self::assertSame([], glob($this->storage->path . '/cache/data/*.trash-*') ?: []);
    }

    public function testRememberComputesOnceAndCachesTheResult(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $compute = static function () use (&$calls): array {
            ++$calls;

            return ['n' => $calls];
        };

        self::assertSame(['n' => 1], $cache->remember('k', 60, $compute));
        self::assertSame(['n' => 1], $cache->remember('k', 60, $compute));
        self::assertSame(1, $calls);
    }

    public function testWithoutStorageEverythingIsAMissAndNothingIsCreated(): void
    {
        $missing = $this->storage->path . '/missing-storage';
        $cache = (new CacheFactory($missing))->namespace('core');

        self::assertFalse($cache->set('k', 1));
        self::assertNull($cache->get('k'));
        self::assertSame(5, $cache->remember('k', null, static fn(): int => 5));
        self::assertTrue($cache->clear());
        self::assertDirectoryDoesNotExist($missing);
    }

    public function testDataStaysInStorageWithRestrictivePermissions(): void
    {
        $this->cache()->set('k', 'v');
        $file = (string) current(glob($this->namespaceDirectory() . '/*/*.cache') ?: []);

        self::assertStringStartsWith($this->storage->path . '/cache/data/core/', $file);

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);

            $directories = [dirname($file), $this->namespaceDirectory(), dirname($this->namespaceDirectory())];

            foreach ($directories as $directory) {
                self::assertSame(0700, fileperms($directory) & 0777, $directory);
            }
        }

        self::assertSame([], glob(dirname($file) . '/*.tmp') ?: [], 'no temporary files left');
    }

    public function testNamespaceNamesAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache_namespace_invalid');

        (new CacheFactory($this->storage->path))->namespace('../public');
    }

    private function cache(): FileCache
    {
        return new FileCache($this->namespaceDirectory(), $this->storage->path, fn(): int => $this->now);
    }

    private function namespaceDirectory(): string
    {
        return $this->storage->path . '/' . CacheFactory::SUBDIRECTORY . '/core';
    }
}
