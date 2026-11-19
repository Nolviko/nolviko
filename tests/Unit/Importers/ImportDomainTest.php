<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Importers;

use InvalidArgumentException;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportText;
use Nolviko\Modules\Importers\Domain\LegacyLink;
use Nolviko\Modules\Importers\Infrastructure\InlineAttachments;
use Nolviko\Modules\Importers\Infrastructure\SourceRecords;
use Nolviko\Modules\Users\Domain\LegacyPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ImportDomainTest extends TestCase
{
    public function testCompositeCursorSortAndMaximumIdRoundTrip(): void
    {
        $values = [[1, 0], [1, 12], [2, 0], [PHP_INT_MAX, PHP_INT_MAX]];
        $keys = array_map(ImportCursor::key(...), $values);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        self::assertSame($keys, $sorted);
        foreach ($keys as $index => $key) {
            self::assertSame($values[$index], ImportCursor::parts($key, 2));
        }
        self::assertSame([0, 0, 0], ImportCursor::parts('', 3));
    }

    #[DataProvider('badCursors')]
    public function testInvalidCursorsFail(string $key): void
    {
        $this->expectException(Throwable::class);
        ImportCursor::parts($key);
    }

    public static function badCursors(): array
    {
        return [['-1'], ['1'], [str_repeat('9', 19)], ['0000000000000000001:0000000000000000002'], ['../x']];
    }

    /**
     * Contract 1 source side (ADR-038 step 3, 2026-11-20): the fingerprint is taken from the raw row (the value the
     * core computed before, so earlier jobs continue), text arrives converted, and inline attachments become neutral
     * markers the core can resolve without knowing the source forum.
     */
    public function testSourceRecordsFingerprintRawRowsAndConvertTextToNeutralMarkers(): void
    {
        $raw = ['id' => 7, '_key' => ImportCursor::key([7]), 'thread' => 1, 'body' => 'A &amp; [attach]12[/attach]'];
        [$smf] = SourceRecords::normalize('smf', 'posts', [$raw]);
        self::assertSame(hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)), $smf['_hash']);
        self::assertSame('A & [nolviko-attachment]12[/nolviko-attachment]', $smf['body']);
        [$mybb] = SourceRecords::normalize('mybb', 'posts', [['body' => 'x [attachment=5] y'] + $raw]);
        self::assertSame('x [nolviko-attachment]5[/nolviko-attachment] y', $mybb['body']);
        [$phpbb] = SourceRecords::normalize('phpbb', 'posts', [
            ['body' => '[b:abc12345]Hi[/b:abc12345] [attachment=0:abc12345]ç.png[/attachment:abc12345]'] + $raw,
        ]);
        self::assertSame('[b]Hi[/b] [nolviko-attachment-name]ç.png[/nolviko-attachment-name]', $phpbb['body']);
        self::assertTrue(InlineAttachments::has($phpbb['body']));
        self::assertFalse(InlineAttachments::has('[attach]12[/attach]'));
        [$user] = SourceRecords::normalize('smf', 'users', [['id' => 2, '_key' => ImportCursor::key([2]),
            'signature' => 'İmza &amp; Çığ']]);
        self::assertSame('İmza & Çığ', $user['signature']);
        $this->expectExceptionMessage('import_text_invalid');
        SourceRecords::normalize('smf', 'private_inbox', [['body' => "bad\0byte"] + $raw]);
    }

    public function testExplicitCharsetPreservesTurkishAndEntitiesWithoutExecutingMarkup(): void
    {
        self::assertSame('İstanbul & Çığ', ImportText::body('İstanbul &amp; Çığ', 'smf', 'UTF-8'));
        self::assertSame('café', ImportText::decode("caf\xe9", 'Windows-1252'));
        self::assertSame('[b]Hello[/b] <script>x</script>', ImportText::body(
            '<r><B><s>[b]</s>Hello<e>[/b]</e></B> &lt;script&gt;x&lt;/script&gt;</r>',
            'phpbb',
            'UTF-8'
        ));
        self::assertSame('[b]Hello[/b]', ImportText::body('[b:abc12345]Hello[/b:abc12345]', 'phpbb', 'UTF-8'));
    }

    #[DataProvider('badText')]
    public function testInvalidOrExternalEntityTextStops(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportText::body($text, 'phpbb', 'UTF-8');
    }

    public static function badText(): array
    {
        return [["bad\0text"], ["\xff"], ['<r><b></r>'],
            ['<r><!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>&x;</r>'],
            ['<r>&unknown;</r>'], [str_repeat('a', 50001)]];
    }

    public function testLegacyCredentialsUpgradeInputRulesAndWrongPassword(): void
    {
        $password = 'test-password-Ç';
        $hashes = [
            ['smf', sha1('legacy' . $password), 'Legacy'],
            ['mybb', md5(md5('fixture-salt') . md5($password)), 'fixture-salt'],
            ['phpbb', password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), ''],
        ];
        foreach ($hashes as [$engine, $hash, $context]) {
            self::assertTrue(LegacyPassword::supported($engine, $hash));
            $tag = LegacyPassword::encode($engine, $hash, $context);
            self::assertTrue(LegacyPassword::verify($password, $tag));
            self::assertFalse(LegacyPassword::verify('wrong', $tag));
        }
        self::assertFalse(LegacyPassword::supported('phpbb', '$2y$31$' . str_repeat('a', 53)));
        self::assertFalse(LegacyPassword::verify(str_repeat('a', 4097), '$nl$phpbb$$invalid'));
    }

    #[DataProvider('legacyLinks')]
    public function testLegacyEndpointGrammar(string $engine, string $path, array $query, ?array $expected): void
    {
        self::assertSame($expected, LegacyLink::parse($engine, $path, $query));
    }

    public static function legacyLinks(): array
    {
        return [
            ['smf', 'index.php', ['topic' => '12.msg34'], ['entity' => 'posts', 'source' => 34]],
            ['smf', 'index.php', ['board' => '12.0'], ['entity' => 'forums', 'source' => 12]],
            ['mybb', 'attachment.php', ['aid' => '12'], ['entity' => 'attachments', 'source' => 12]],
            ['phpbb', 'viewtopic.php', ['p' => '34'], ['entity' => 'posts', 'source' => 34]],
            ['phpbb', 'download/file.php', ['id' => '12'], ['entity' => 'attachments', 'source' => 12]],
            ['mybb', 'showthread.php', ['tid' => 'https://evil.test/'], null],
            ['mybb', 'showthread.php', ['tid' => ['12']], null],
            ['phpbb', 'viewtopic.php', ['t' => '9999999999999999999'], null],
            ['smf', 'https://evil.test/', ['topic' => '12'], null],
        ];
    }
}
