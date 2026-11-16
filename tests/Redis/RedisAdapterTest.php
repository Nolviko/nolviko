<?php

declare(strict_types=1);

namespace Nolviko\Tests\Redis;

use LogicException;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Cache\FailoverCache;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Cache\RedisCache;
use Nolviko\Core\Cache\RedisConnection;
use Nolviko\Core\Cache\RedisOptions;
use Nolviko\Core\Cache\RedisSettingsStore;
use Nolviko\Core\Config\Secret;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Dedicated loopback Redis fixture: no shared databases or FLUSH commands. */
final class RedisAdapterTest extends TestCase
{
    private TemporaryDirectory $scratch;

    private RedisOptions $options;

    private RedisSettingsStore $store;

    protected function setUp(): void
    {
        $password = getenv('FORUM_TEST_REDIS_PASSWORD');

        if (!is_string($password) || $password === '') {
            self::fail('dedicated Redis fixture required');
        }

        $this->scratch = new TemporaryDirectory();
        $this->options = new RedisOptions('127.0.0.1', 6379, $password, tls: false);
        $this->store = new RedisSettingsStore($this->scratch->path);
    }

    protected function tearDown(): void
    {
        if (isset($this->scratch)) {
            $this->scratch->remove();
        }
    }

    public function testPreflightActivationEncryptedSettingsAndFailedReplacement(): void
    {
        $this->store->activate($this->options);
        $before = $this->store->status();
        self::assertTrue($before['enabled']);
        self::assertStringNotContainsString(
            $this->options->password->reveal(),
            file_get_contents($this->scratch->path . '/redis/settings')
        );

        try {
            $this->store->activate(new RedisOptions('127.0.0.1', 1, tls: false));
            self::fail('unreachable replacement activated');
        } catch (RuntimeException) {
            self::assertSame($before, $this->store->status());
        }

        $this->store->disable();
        self::assertFalse($this->store->status()['enabled']);
        self::assertNotSame($before['epoch'], $this->store->status()['epoch']);
    }

    public function testNativeCacheTtlNullFalseCorruptionAndNamespaceClear(): void
    {
        $connection = new RedisConnection($this->options);
        $prefix = 'm08:' . bin2hex(random_bytes(16));
        $key = new Secret(random_bytes(32));
        $locks = new FileCache($this->scratch->path . '/locks', $this->scratch->path);
        $cache = new RedisCache($connection, $prefix, $key, $locks);
        $other = new RedisCache($connection, $prefix . '-other', $key, $locks);
        self::assertTrue($cache->set('null', null));
        self::assertTrue($cache->has('null'));
        self::assertNull($cache->get('null', 'missing'));
        self::assertTrue($cache->set('false', false, 30));
        self::assertFalse($cache->get('false', true));
        self::assertTrue($cache->set('private', ['secret' => 'private-fragment'], 30));
        $generation = $connection->command(['GET', $prefix . ':generation']);
        $name = $prefix . ':' . $generation . ':' . hash('sha256', 'private');
        $raw = $connection->command(['GET', $name]);
        self::assertStringNotContainsString('private-fragment', $raw);
        $connection->command(['SET', $name, 'not-an-authenticated-value', 'EX', '30']);
        self::assertSame('missing', $cache->get('private', 'missing'));
        self::assertTrue($cache->set('expired', 'value', 1));
        usleep(1100000);
        self::assertFalse($cache->has('expired'));
        self::assertTrue($cache->set('removed', 1, -1));
        self::assertFalse($cache->has('removed'));
        $other->set('survivor', 7, 30);
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('null'));
        self::assertSame(7, $other->get('survivor'));
    }

    public function testOutageDisablesEpochAndReactivationCannotReadStaleEntries(): void
    {
        $this->store->activate($this->options);
        $factory = new CacheFactory($this->scratch->path);
        $cache = $factory->namespace('theme-render');
        self::assertTrue($cache->set('prior', 'old-value', 60));
        $epoch = $this->store->status()['epoch'];
        $broken = $this->createStub(Cache::class);
        $broken->method('get')->willThrowException(new RuntimeException('redis_unavailable'));
        $local = fn(string $value): Cache => new FileCache($this->scratch->path . '/local-'
            . substr(hash('sha256', $value), 0, 20), $this->scratch->path);
        $fallback = new FailoverCache($broken, $this->store, $epoch, $local);
        self::assertSame('miss', $fallback->get('prior', 'miss'));
        self::assertFalse($this->store->status()['enabled']);
        self::assertTrue($fallback->set('new', 'fresh', 60));
        self::assertSame('fresh', $fallback->get('new'));
        $this->store->activate($this->options);
        self::assertNotSame($epoch, $this->store->status()['epoch']);
        self::assertSame('missing', $factory->namespace('theme-render')->get('prior', 'missing'));
        self::assertInstanceOf(FileCache::class, $factory->namespace('permissions'));
    }

    public function testRememberDoesNotRepeatABusinessFailureAndCommandsAreAllowlisted(): void
    {
        $this->store->activate($this->options);
        $cache = (new CacheFactory($this->scratch->path))->namespace('theme-render');
        $calls = 0;

        try {
            $cache->remember('business', 30, static function () use (&$calls): never {
                ++$calls;
                throw new LogicException('expected_business_failure');
            });
            self::fail('business failure swallowed');
        } catch (LogicException) {
            self::assertSame(1, $calls);
            self::assertTrue($this->store->status()['enabled']);
        }

        $this->expectException(RuntimeException::class);
        (new RedisConnection($this->options))->command(['FLUSHDB']);
    }

    public function testVerifiedTlsRejectsPlaintextAndInvalidCredentials(): void
    {
        try {
            $this->store->preflight(new RedisOptions('127.0.0.1', 6379, 'wrong', tls: false));
            self::fail('wrong credential accepted');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('wrong', $failure->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->store->preflight(new RedisOptions('127.0.0.1', 6379, tls: true));
    }

    public function testTlsCertificateAndHostnameAreVerified(): void
    {
        $options = new RedisOptions(
            '127.0.0.1',
            6380,
            $this->options->password->reveal(),
            tls: true,
            peerName: 'localhost'
        );
        self::assertSame('PONG', (new RedisConnection($options, '/work/tls/ca.crt'))->command(['PING']));
        $this->expectException(RuntimeException::class);
        $wrong = new RedisOptions(
            '127.0.0.1',
            6380,
            $this->options->password->reveal(),
            tls: true,
            peerName: 'wrong.example.test'
        );
        (new RedisConnection($wrong, '/work/tls/ca.crt'))->command(['PING']);
    }

    public function testIdenticalWarmFixturesRecordFileAndRedisCosts(): void
    {
        $file = new FileCache($this->scratch->path . '/baseline', $this->scratch->path);
        $this->store->activate($this->options);
        $redis = (new CacheFactory($this->scratch->path))->namespace('theme-render');
        $payload = ['public' => str_repeat('bounded fixture ', 100), 'false' => false, 'null' => null];
        $measurements = [];

        foreach (['filesystem' => $file, 'redis' => $redis] as $name => $cache) {
            self::assertTrue($cache->set('same', $payload, 60));
            self::assertSame($payload, $cache->get('same'));
            $measurements[$name] = BenchmarkHarness::measure(static fn(): mixed => $cache->get('same'), 100, 10);
        }

        fwrite(STDOUT, 'm08_cache_compare ' . json_encode(['cases' => $measurements, 'sql' => 0,
            'payload_bytes' => strlen(serialize($payload))], JSON_THROW_ON_ERROR) . "\n");
    }
}
