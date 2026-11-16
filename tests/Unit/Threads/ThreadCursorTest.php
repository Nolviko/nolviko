<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Threads;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M03.11: the keyset cursor round-trips exactly (microseconds included) and refuses every token it did not make.
 */
final class ThreadCursorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $at = new DateTimeImmutable('2026-11-01 12:34:56.789012', new DateTimeZone('UTC'));
        $token = (new ThreadCursor(true, $at, 4711))->encode();
        $cursor = ThreadCursor::decode($token);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $token, 'URL-safe, no padding');
        self::assertNotNull($cursor);
        self::assertTrue($cursor->sticky);
        self::assertSame(4711, $cursor->threadId);
        self::assertSame('2026-11-01 12:34:56.789012', $cursor->timestamp());
    }

    public function testOtherTimeZonesAreStoredAsUtc(): void
    {
        $at = new DateTimeImmutable('2026-11-01 15:00:00', new DateTimeZone('Europe/Istanbul'));

        $token = (new ThreadCursor(false, $at, 1))->encode();

        self::assertSame('2026-11-01 12:00:00.000000', ThreadCursor::decode($token)?->timestamp());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        $encode = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        yield 'empty' => [''];
        yield 'not base64url' => ['abc+/='];
        yield 'too long' => [str_repeat('A', 65)];
        yield 'plain text' => ['hello'];
        yield 'other version' => [$encode('2|0|2026-11-01 00:00:00.000000|1')];
        yield 'sticky not a flag' => [$encode('1|2|2026-11-01 00:00:00.000000|1')];
        yield 'impossible date' => [$encode('1|0|2026-13-40 00:00:00.000000|1')];
        yield 'no microseconds' => [$encode('1|0|2026-11-01 00:00:00|1')];
        yield 'zero id' => [$encode('1|0|2026-11-01 00:00:00.000000|0')];
        yield 'leading zero id' => [$encode('1|0|2026-11-01 00:00:00.000000|01')];
        yield 'negative id' => [$encode('1|0|2026-11-01 00:00:00.000000|-1')];
        yield 'SQL in id' => [$encode("1|0|2026-11-01 00:00:00.000000|1 OR 1=1")];
        yield 'trailing newline' => [$encode("1|0|2026-11-01 00:00:00.000000|1\n")];
    }

    #[DataProvider('rejected')]
    public function testForeignTokensAreRefused(string $token): void
    {
        self::assertNull(ThreadCursor::decode($token));
    }
}
