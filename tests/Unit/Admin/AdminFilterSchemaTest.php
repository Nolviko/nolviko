<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Admin;

use InvalidArgumentException;
use Nolviko\Modules\Admin\Domain\AdminFilterSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Saved searches accept data from a closed schema, never URLs, actions or pagination cursors. */
final class AdminFilterSchemaTest extends TestCase
{
    public function testUnicodeAndCursorHandling(): void
    {
        self::assertSame(['q' => 'Türkçe İ اختبار'], AdminFilterSchema::clean(
            'settings',
            ['q' => ' Türkçe İ اختبار ', 'before' => '123', 'group' => '']
        ));
        self::assertSame(['name' => '<script>'], AdminFilterSchema::clean('members', ['name' => '<script>']));
        self::assertSame(['family' => 'admin', 'target_id' => '123'], AdminFilterSchema::clean(
            'audit',
            ['target_id' => '123', 'family' => 'admin']
        ));
    }

    #[DataProvider('invalid')]
    public function testRejectsUnknownAndMalformedFilters(string $context, array $filters): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminFilterSchema::clean($context, $filters);
    }

    public static function invalid(): array
    {
        return [
            ['unknown', []], ['settings', ['url' => 'https://example.com']],
            ['members', ['action' => 'suspend']], ['settings', ['q' => ['nested']]],
            ['settings', ['q' => "bad\0text"]], ['settings', ['q' => "\xff"]],
            ['settings', ['q' => str_repeat('a', 121)]], ['settings', ['group' => 'secrets']],
            ['members', ['status' => 'unknown']], ['audit', ['family' => 'unknown']],
            ['audit', ['target_id' => '-1']], ['audit', ['target_type' => 'not a type']],
        ];
    }
}
