<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.06 on MySQL 8.4: built-in groups, memberships with foreign keys and explicit delete behaviour, and the
 * primary-group rules.
 */
final class UsergroupRepositoryTest extends TestCase
{
    private Connection $connection;

    private DbalUsergroupRepository $groups;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->groups = new DbalUsergroupRepository($this->connection);
        $users = new DbalUserRepository($this->connection);
        $this->alice = $users->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->bob = $users->create('bob', 'bob@example.com', 'h', UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testBuiltInGroupsHaveStableIdsAndCannotBeDeleted(): void
    {
        $all = $this->groups->all();

        self::assertSame(
            [[1, 'guests'], [2, 'registered'], [3, 'moderators'], [4, 'administrators']],
            array_map(static fn($group): array => [$group->id, $group->slug], $all),
        );
        self::assertTrue($all[0]->builtIn);
        self::assertSame(BuiltInGroup::Administrators->value, $this->groups->findBySlug('administrators')?->id);

        foreach (BuiltInGroup::cases() as $builtIn) {
            try {
                $this->groups->delete($builtIn->value);
                self::fail('deleted ' . $builtIn->slug());
            } catch (GroupRuleViolation $violation) {
                self::assertSame('group_builtin_protected', $violation->diagnostic);
            }
        }

        self::assertCount(4, $this->groups->all());
    }

    public function testCustomGroupsStartAtTheReservedOffsetAndSlugsAreChecked(): void
    {
        $vip = $this->groups->create('vip', 'Very important');

        self::assertSame(BuiltInGroup::FIRST_CUSTOM_ID, $vip->id);
        self::assertFalse($vip->builtIn);

        $invalid = [
            'vip' => 'group_slug_taken',
            'VIP' => 'group_slug_invalid',
            'a' => 'group_slug_invalid',
            '1st' => 'group_slug_invalid',
            'has space' => 'group_slug_invalid',
        ];

        foreach ($invalid as $slug => $diagnostic) {
            try {
                $this->groups->create($slug, 'x');
                self::fail('accepted ' . $slug);
            } catch (GroupRuleViolation $violation) {
                self::assertSame($diagnostic, $violation->diagnostic, $slug);
            }
        }
    }

    public function testFirstMembershipIsPrimaryAndPrimaryCanMove(): void
    {
        $vip = $this->groups->create('vip', 'VIP')->id;
        $this->groups->addMember($this->alice, BuiltInGroup::Registered->value);
        $this->groups->addMember($this->alice, $vip);
        $this->groups->addMember($this->alice, $vip);

        self::assertSame([BuiltInGroup::Registered->value, $vip], $this->groups->groupIdsOf($this->alice));
        self::assertSame(BuiltInGroup::Registered->value, $this->groups->primaryGroupOf($this->alice));

        $this->groups->setPrimary($this->alice, $vip);

        self::assertSame([$vip, BuiltInGroup::Registered->value], $this->groups->groupIdsOf($this->alice));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM usergroup_members WHERE user_id = ? AND is_primary = 1',
            [$this->alice],
        ));

        $this->groups->addMember($this->alice, BuiltInGroup::Moderators->value, primary: true);
        self::assertSame(BuiltInGroup::Moderators->value, $this->groups->primaryGroupOf($this->alice));
    }

    public function testTheDatabaseRejectsASecondPrimary(): void
    {
        $this->groups->addMember($this->alice, BuiltInGroup::Registered->value);
        $this->groups->addMember($this->alice, BuiltInGroup::Moderators->value);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->update(
            'usergroup_members',
            ['is_primary' => 1],
            ['user_id' => $this->alice, 'usergroup_id' => BuiltInGroup::Moderators->value],
        );
    }

    public function testMembershipRules(): void
    {
        $this->groups->addMember($this->bob, BuiltInGroup::Registered->value);
        $this->groups->addMember($this->bob, BuiltInGroup::Moderators->value);
        $cases = [
            'guests have no members' => [
                fn() => $this->groups->addMember($this->bob, BuiltInGroup::Guests->value),
                'group_guests_no_members',
            ],
            'unknown group' => [fn() => $this->groups->addMember($this->bob, 999), 'group_not_found'],
            'primary cannot be removed' => [
                fn() => $this->groups->removeMember($this->bob, BuiltInGroup::Registered->value),
                'group_primary_required',
            ],
            'primary must be a membership' => [
                fn() => $this->groups->setPrimary($this->bob, BuiltInGroup::Administrators->value),
                'group_not_member',
            ],
            'unknown group cannot be deleted' => [fn() => $this->groups->delete(999), 'group_not_found'],
        ];

        foreach ($cases as $name => [$action, $diagnostic]) {
            try {
                $action();
                self::fail($name);
            } catch (GroupRuleViolation $violation) {
                self::assertSame($diagnostic, $violation->diagnostic, $name);
            }
        }

        $this->groups->removeMember($this->bob, BuiltInGroup::Moderators->value);
        self::assertSame([BuiltInGroup::Registered->value], $this->groups->groupIdsOf($this->bob));
    }

    public function testDeletingACustomGroupRemovesMembershipsAndRestoresAPrimary(): void
    {
        $vip = $this->groups->create('vip', 'VIP')->id;
        $this->groups->addMember($this->alice, $vip);
        $this->groups->addMember($this->bob, BuiltInGroup::Registered->value);
        $this->groups->addMember($this->bob, $vip);

        $this->groups->delete($vip);

        self::assertNull($this->groups->find($vip));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM usergroup_members WHERE usergroup_id = ?',
            [$vip],
        ));
        self::assertSame(BuiltInGroup::Registered->value, $this->groups->primaryGroupOf($this->alice), 'fallback');
        self::assertSame([BuiltInGroup::Registered->value], $this->groups->groupIdsOf($this->bob));
    }

    public function testDeletingAUserRemovesTheirMemberships(): void
    {
        $this->groups->addMember($this->alice, BuiltInGroup::Registered->value);
        $this->connection->delete('users', ['id' => $this->alice]);

        self::assertSame([], $this->groups->groupIdsOf($this->alice));
    }
}
