<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Logging;

use Nolviko\Core\Logging\IpPolicy;
use Nolviko\Core\Logging\JsonFileLogger;
use Nolviko\Core\Logging\Redactor;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JsonFileLoggerTest extends TestCase
{
    /** 2026-10-31 12:34:56.789 UTC. */
    private const NOW = 1793450096.789;

    private const DAY = 86400;

    private TemporaryDirectory $temporary;

    private float $now = self::NOW;

    /** @var list<string> */
    private array $fallback = [];

    protected function setUp(): void
    {
        $this->temporary = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->temporary->remove();
    }

    public function testWritesOneRedactedJsonObjectPerLineToTheDailyFile(): void
    {
        $logger = $this->logger();

        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->error('unhandled_exception', [
            'incident' => '0123456789abcdef',
            'exception' => new RuntimeException("first line\npassword=hunter2"),
            'password' => 'hunter2',
        ]);
        $logger->info('second');

        $file = $this->directory() . '/nolviko-2026-10-31.log';
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(2, $lines);
        $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2026-10-31T12:34:56.789Z', $first['time']);
        self::assertSame('error', $first['level']);
        self::assertSame('unhandled_exception', $first['message']);
        self::assertSame('0123456789abcdef', $first['context']['incident']);
        self::assertSame(Redactor::REDACTED, $first['context']['password']);
        self::assertSame("first line\npassword=" . Redactor::REDACTED, $first['context']['exception']['message']);
        self::assertStringNotContainsString('hunter2', (string) file_get_contents($file));
        $second = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['time' => '2026-10-31T12:34:56.789Z', 'level' => 'info', 'message' => 'second'], $second);
    }

    public function testUnknownLevelIsRejectedAsPsr3Requires(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger()->log('fatal', 'x');
    }

    public function testNewDayStartsANewFileAndDeletesExpiredOnes(): void
    {
        $logger = $this->logger(retentionDays: 2);
        $logger->warning('day one');
        $this->now += self::DAY;
        $logger->warning('day two');
        $this->now += 2 * self::DAY;
        $logger->warning('day four');

        $files = array_map('basename', glob($this->directory() . '/*.log') ?: []);
        self::assertSame(['nolviko-2026-11-01.log', 'nolviko-2026-11-03.log'], $files);
    }

    public function testDailyFileStopsAtTheSizeCapWithOneMarker(): void
    {
        $logger = $this->logger(maxBytes: 400);

        for ($i = 0; $i < 20; ++$i) {
            $logger->notice('entry ' . $i, ['padding' => str_repeat('x', 40)]);
        }

        $file = $this->directory() . '/nolviko-2026-10-31.log';
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $last = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(JsonFileLogger::CAP_MESSAGE, $last['message']);
        self::assertSame(1, substr_count((string) file_get_contents($file), JsonFileLogger::CAP_MESSAGE));
        self::assertLessThan(400 + 120, filesize($file));
        self::assertLessThan(20, count($lines));
    }

    public function testClientIpFollowsThePrivacySetting(): void
    {
        $lines = [];

        foreach ([IpPolicy::Full, IpPolicy::Truncated, IpPolicy::None] as $policy) {
            $temporary = new TemporaryDirectory();
            (new JsonFileLogger($temporary->path, ipPolicy: $policy, clock: fn(): float => $this->now))
                ->error('x', ['client_ip' => '203.0.113.77', 'path' => '/']);
            $lines[$policy->value] = json_decode(
                (string) file_get_contents($temporary->path . '/nolviko-2026-10-31.log'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $temporary->remove();
        }

        self::assertSame('203.0.113.77', $lines['full']['client_ip']);
        self::assertSame('203.0.113.0', $lines['truncated']['client_ip']);
        self::assertArrayNotHasKey('client_ip', $lines['none']);
        self::assertSame(['path' => '/'], $lines['none']['context'], 'the address never moves into context');
    }

    public function testUnwritableDirectoryNeverThrowsAndUsesTheFallback(): void
    {
        // A path below a regular file can never be created, even by root (tests run as root in Docker).
        $blocked = $this->temporary->write('storage', 'not a directory') . '/logs';
        $logger = new JsonFileLogger(
            $blocked,
            clock: fn(): float => $this->now,
            fallback: function (string $line): void {
                $this->fallback[] = $line;
            },
        );

        $logger->critical('disk full', ['token' => 'abc']);
        $logger->error('still fine');

        self::assertCount(2, $this->fallback);
        self::assertStringNotContainsString('abc', $this->fallback[0]);
        self::assertDirectoryDoesNotExist($blocked);
    }

    public function testMissingStorageIsNotCreatedByLogging(): void
    {
        $logs = $this->temporary->path . '/missing-storage/logs';

        $this->logger($logs)->error('x');

        self::assertDirectoryDoesNotExist(dirname($logs));
        self::assertCount(1, $this->fallback);
    }

    private function logger(
        ?string $directory = null,
        int $maxBytes = JsonFileLogger::DEFAULT_MAX_BYTES,
        int $retentionDays = JsonFileLogger::DEFAULT_RETENTION_DAYS,
    ): JsonFileLogger {
        return new JsonFileLogger(
            $directory ?? $this->directory(),
            maxBytes: $maxBytes,
            retentionDays: $retentionDays,
            clock: fn(): float => $this->now,
            fallback: function (string $line): void {
                $this->fallback[] = $line;
            },
        );
    }

    private function directory(): string
    {
        return $this->temporary->path;
    }
}
