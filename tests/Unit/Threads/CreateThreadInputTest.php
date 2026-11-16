<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Threads;

use Nolviko\Modules\Threads\Domain\CreateThread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M03.04 input rules: title and content normalization and limits, and the ASCII slug.
 */
final class CreateThreadInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function titles(): iterable
    {
        yield 'trimmed and collapsed' => ["  Hello \t\n  world\u{00A0} ", 'Hello world'];
        yield 'NFC' => ["Cafe\u{0301}", "Caf\u{00E9}"];
        yield 'emoji kept' => ['Hi 👋🏽', 'Hi 👋🏽'];
        yield 'empty' => ['   ', null];
        yield 'only ideographic space' => ["\u{3000}", null];
        yield 'control character' => ["a\u{0007}b", null];
        yield 'invalid UTF-8' => ["a\xC3", null];
        yield 'at the limit' => [str_repeat('ş', CreateThread::MAX_TITLE_LENGTH), str_repeat('ş', 200)];
        yield 'over the limit' => [str_repeat('a', CreateThread::MAX_TITLE_LENGTH + 1), null];
    }

    #[DataProvider('titles')]
    public function testTitle(string $input, ?string $expected): void
    {
        self::assertSame($expected, CreateThread::title($input));
    }

    public function testContent(): void
    {
        $limit = str_repeat('ğ', CreateThread::MAX_CONTENT_LENGTH);

        self::assertSame("a\nb\nc", CreateThread::content("\n a\r\nb\rc \n"));
        self::assertSame($limit, CreateThread::content($limit), 'characters, not bytes');
        self::assertNull(CreateThread::content(str_repeat('a', 50001)), 'never truncated');
        self::assertNull(CreateThread::content(" \n\t "));
        self::assertNull(CreateThread::content("a\0b"));
        self::assertNull(CreateThread::content("\xFF"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield 'ascii' => ['Hello, World!', 'hello-world'];
        yield 'turkish' => ['Şu çılgın Türk ğıdası', 'su-cilgin-turk-gidasi'];
        yield 'german' => ['Grüße aus Köln', 'grusse-aus-koln'];
        yield 'cyrillic' => ['Привет мир', 'privet-mir'];
        yield 'nothing left' => ['!!! ???', 'thread'];
        yield 'cut at a word boundary' => [str_repeat('abcdefghi ', 10), rtrim(str_repeat('abcdefghi-', 8), '-')];
        yield 'one long word' => [str_repeat('a', 100), str_repeat('a', 80)];
    }

    #[DataProvider('slugs')]
    public function testSlug(string $title, string $expected): void
    {
        $slug = CreateThread::slug($title);

        self::assertSame($expected, $slug);
        self::assertLessThanOrEqual(CreateThread::MAX_SLUG_LENGTH, strlen($slug));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug);
    }
}
