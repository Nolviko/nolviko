<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Tasks;

use InvalidArgumentException;
use Nolviko\Core\Application\TaskCli;
use Nolviko\Core\Application\TaskConsent;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Cache\RedisOptions;
use Nolviko\Core\Cache\RedisSettingsStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Safe endpoint validation, unchanged file TTL semantics and explicit CLI opt-in. */
final class TaskCacheContractsTest extends TestCase
{
    #[DataProvider('invalidOptions')]
    public function testRedisRejectsUnsafeOptions(string $host, int $port, bool $tls): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RedisOptions($host, $port, tls: $tls);
    }

    public static function invalidOptions(): array
    {
        return [['example.test', 6379, true], ['127.0.0.1/path', 6379, true], ['127.0.0.1', 0, true],
            ['127.0.0.1', 65536, true], ['192.0.2.1', 6379, false], ['169.254.169.254', 6379, true],
            ['0.0.0.0', 6379, false], ['::', 6379, true], ['fe80::1', 6379, true]];
    }

    public function testCredentialObjectDoesNotExposeItsPassword(): void
    {
        $options = new RedisOptions('127.0.0.1', 6379, 'test-only-secret', tls: false);
        self::assertStringNotContainsString('test-only-secret', json_encode($options, JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($options);
        $dump = ob_get_clean();
        self::assertStringNotContainsString('test-only-secret', $dump);
        $this->expectException(InvalidArgumentException::class);
        new RedisOptions('127.0.0.1', 6379, tls: false, namespaces: ['permissions']);
    }

    public function testFileFallbackContractKeepsNullFalseTtlAndNamespaceIsolation(): void
    {
        $scratch = new TemporaryDirectory();

        try {
            $clock = 100;
            $one = new FileCache($scratch->path . '/one', $scratch->path, static function () use (&$clock): int {
                return $clock;
            });
            $two = new FileCache($scratch->path . '/two', $scratch->path);
            self::assertTrue($one->set('null', null, 1));
            self::assertTrue($one->has('null'));
            $clock = 102;
            self::assertFalse($one->has('null'));
            self::assertTrue($one->set('false', false));
            self::assertFalse($one->get('false', true));
            self::assertFalse($two->has('false'));
            self::assertTrue($one->set('false', 'gone', 0));
            self::assertFalse($one->has('false'));
            self::assertTrue($two->set('survivor', 1));
            self::assertTrue($one->clear());
            self::assertSame(1, $two->get('survivor'));
        } finally {
            $scratch->remove();
        }
    }

    public function testCliRequiresExplicitPrivateConsentAndRejectsLinks(): void
    {
        $scratch = new TemporaryDirectory();

        try {
            $consent = new TaskConsent($scratch->path);
            self::assertFalse($consent->enabled());
            $consent->set(true);
            self::assertTrue($consent->enabled());
            $consent->set(false);
            self::assertFalse($consent->enabled());
            $scratch->write('outside', 'unchanged');
            symlink($scratch->path . '/outside', $scratch->path . '/tasks/cli.enabled');
            self::assertFalse($consent->enabled());

            try {
                $consent->set(true);
                self::fail('linked marker accepted');
            } catch (RuntimeException) {
                self::assertSame('unchanged', file_get_contents($scratch->path . '/outside'));
            }
        } finally {
            $scratch->remove();
        }
    }

    public function testCliEnableAndDisableCommandsApplyConsentWithoutStartingAWorker(): void
    {
        $scratch = new TemporaryDirectory();
        $scratch->write('public/.keep', '');
        $scratch->write('storage/.keep', '');
        $this->expectOutputString("{\"enabled\":true}\n{\"enabled\":false}\n");

        try {
            $environment = ['FORUM_STORAGE_DIR' => $scratch->path . '/storage'];
            self::assertSame(0, TaskCli::run($scratch->path, $environment, ['--enable']));
            self::assertTrue((new TaskConsent($scratch->path . '/storage'))->enabled());
            self::assertSame(0, TaskCli::run($scratch->path, $environment, ['--disable']));
            self::assertFalse((new TaskConsent($scratch->path . '/storage'))->enabled());
        } finally {
            $scratch->remove();
        }
    }

    public function testUnconfiguredRedisIsDisabledWithoutCreatingCredentials(): void
    {
        $scratch = new TemporaryDirectory();

        try {
            $state = (new RedisSettingsStore($scratch->path))->status();
            self::assertFalse($state['enabled']);
            self::assertDirectoryDoesNotExist($scratch->path . '/redis');
            $cache = (new CacheFactory($scratch->path))->namespace('theme-render');
            self::assertTrue($cache->set('key', 'value', 30));
            self::assertSame('value', $cache->get('key'));
        } finally {
            $scratch->remove();
        }
    }
}
