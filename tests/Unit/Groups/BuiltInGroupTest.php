<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Groups;

use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\UsergroupsTable;
use PHPUnit\Framework\TestCase;

/**
 * M02.06: the built-in groups are a stable contract and are seeded by the migration exactly once.
 */
final class BuiltInGroupTest extends TestCase
{
    public function testIdsAndSlugsAreStable(): void
    {
        $contract = array_map(
            static fn(BuiltInGroup $group): array => [$group->value, $group->slug()],
            BuiltInGroup::cases(),
        );

        self::assertSame([[1, 'guests'], [2, 'registered'], [3, 'moderators'], [4, 'administrators']], $contract);
        self::assertTrue(BuiltInGroup::isBuiltIn(4));
        self::assertFalse(BuiltInGroup::isBuiltIn(BuiltInGroup::FIRST_CUSTOM_ID));
    }

    public function testMigrationSeedsEveryBuiltInGroupAndIsRegistered(): void
    {
        $statements = (new UsergroupsTable())->statements('mysql');
        $seed = $statements[1];

        foreach (BuiltInGroup::cases() as $group) {
            self::assertStringContainsString(sprintf("(%d, '%s',", $group->value, $group->slug()), $seed);
        }

        self::assertStringContainsString('AUTO_INCREMENT=' . BuiltInGroup::FIRST_CUSTOM_ID, $statements[0]);
        self::assertNotNull(MigrationRegistry::core()->find(UsergroupsTable::ID));
    }
}
