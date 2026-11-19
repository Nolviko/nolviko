<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Importers;

use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\LegacyLink;
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
