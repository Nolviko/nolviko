<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Permissions;

use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.07: namespaced keys, typed values validated on save, and unknown keys (fail in development, denied in
 * production).
 */
final class PermissionCatalogTest extends TestCase
{
    public function testCoreCatalogIsValidSortedAndNamespaced(): void
    {
        $keys = array_keys(PermissionCatalog::core()->all());
        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys);
        self::assertContains('forum.thread.create', $keys);

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(PermissionDefinition::KEY_PATTERN, $key);
            self::assertContains(explode('.', $key)[0], ['forum', 'user', 'moderation', 'admin'], $key);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'no namespace' => ['view'],
            'upper case' => ['Forum.view'],
            'empty segment' => ['forum..view'],
            'digit first' => ['forum.1view'],
            'dash' => ['forum.thread-create'],
            'six segments' => ['a.b.c.d.e.f'],
            'too long' => ['forum.' . str_repeat('x', 100)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRefused(string $key): void
    {
        $this->expectException(PermissionError::class);
        $this->expectExceptionMessage('permission_key_invalid:');

        PermissionDefinition::bool($key);
    }

    public function testInvalidDefinitionsAndDuplicatesAreRefused(): void
    {
        $cases = [
            'enum needs two options' => static fn() => PermissionDefinition::enum('a.b', ['only']),
            'enum options are identifiers' => static fn() => PermissionDefinition::enum('a.b', ['ok', 'Not Ok']),
            'enum options are unique' => static fn() => PermissionDefinition::enum('a.b', ['x', 'x']),
            'int range is ordered' => static fn() => PermissionDefinition::int('a.b', 5, 1),
            'bool has no options' => static fn() => new PermissionDefinition('a.b', PermissionType::Bool, ['x', 'y']),
            'duplicate key' => static fn() => new PermissionCatalog([
                PermissionDefinition::bool('a.b'),
                PermissionDefinition::int('a.b', 0, 1),
            ]),
        ];

        foreach ($cases as $name => $build) {
            try {
                $build();
                self::fail($name);
            } catch (PermissionError $error) {
                $expected = '/^permission_(definition_invalid|key_duplicate):a\.b$/';

                self::assertMatchesRegularExpression($expected, $error->diagnostic, $name);
            }
        }
    }

    /**
     * @return array<string, array{0: PermissionDefinition, 1: mixed, 2: string|null}>
     */
    public static function values(): array
    {
        $bool = PermissionDefinition::bool('forum.view');
        $int = PermissionDefinition::int('forum.post.edit_window_minutes', 0, 10080);
        $enum = PermissionDefinition::enum('forum.post.approval', ['none', 'first_posts', 'all']);

        return [
            'bool true' => [$bool, true, '1'],
            'bool "0"' => [$bool, '0', '0'],
            'bool int 1' => [$bool, 1, '1'],
            'bool "yes" is not coerced' => [$bool, 'yes', null],
            'bool 2' => [$bool, 2, null],
            'bool null' => [$bool, null, null],
            'int' => [$int, 30, '30'],
            'int string' => [$int, '10080', '10080'],
            'int below range' => [$int, -1, null],
            'int above range' => [$int, 10081, null],
            'int float' => [$int, 1.5, null],
            'int with spaces' => [$int, ' 5', null],
            'int leading zero' => [$int, '05', null],
            'int exponent' => [$int, '1e3', null],
            'enum option' => [$enum, 'first_posts', 'first_posts'],
            'enum case differs' => [$enum, 'ALL', null],
            'enum unknown' => [$enum, 'some', null],
            'enum array' => [$enum, ['all'], null],
        ];
    }

    #[DataProvider('values')]
    public function testValuesAreValidatedBeforeTheyCanBeSaved(
        PermissionDefinition $definition,
        mixed $value,
        ?string $stored,
    ): void {
        if ($stored === null) {
            $this->expectException(PermissionError::class);
            $this->expectExceptionMessage('permission_value_invalid:' . $definition->key);
        }

        self::assertSame($stored, $definition->encode($value));
    }

    public function testStoredValuesDecodeToTheirType(): void
    {
        self::assertTrue(PermissionDefinition::bool('a.b')->decode('1'));
        self::assertSame(30, PermissionDefinition::int('a.b', 0, 60)->decode('30'));
        self::assertSame('all', PermissionDefinition::enum('a.b', ['none', 'all'])->decode('all'));

        $this->expectExceptionMessage('permission_value_invalid:a.b');
        PermissionDefinition::enum('a.b', ['none', 'all'])->decode('removed_option');
    }

    public function testUnknownKeyFailsInDevelopmentAndIsDeniedInProduction(): void
    {
        self::assertNull(PermissionCatalog::core(strict: false)->find('forum.no_such_thing'));
        self::assertNotNull(PermissionCatalog::core(strict: true)->find('forum.view'));

        $this->expectException(PermissionError::class);
        $this->expectExceptionMessage('permission_unknown:forum.no_such_thing');
        PermissionCatalog::core(strict: true)->find('forum.no_such_thing');
    }
}
