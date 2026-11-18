<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Admin\Infrastructure\ForumGuestVisibility;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Beta.10 forum visibility presets: plain choices stored as group rules, read back as the same preset, a guest's
 * reachable forums (the scope search, RSS, sitemaps and the API use) follow at once, staff keep hidden forums, and
 * unrelated rules are never overwritten.
 */
final class ForumGuestVisibilityTest extends TestCase
{
    private Connection $connection;

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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testPresetsDriveWhatGuestsMembersAndStaffSee(): void
    {
        $catalog = PermissionCatalog::core();
        $rules = new DbalPermissionRuleStore($this->connection, $catalog);
        $presets = new ForumGuestVisibility($this->connection, $rules);
        $forums = new DbalForumRepository($this->connection);
        $category = $forums->create(ForumType::Category, 'Club', 'club')->id;
        $forum = $forums->create(ForumType::Forum, 'Lounge', 'lounge', $category)->id;
        $connection = $this->connection;
        $resolver = static fn(): PermissionResolver => new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($connection, $catalog),
            new DbalForumTree($connection),
        );
        $sees = static function (array $groups, int $forum) use ($resolver): string {
            $subject = $groups === [] ? $resolver()->guest() : $resolver()->user(999, $groups);

            return ($subject->allows(ForumVisibility::VIEW, $forum) ? 'name' : '-')
                . ($subject->allows(ForumVisibility::READ, $forum) ? '+threads' : '');
        };

        self::assertSame(ForumGuestVisibility::PUBLIC, $presets->presets([])[0], 'fresh install: public site');
        self::assertSame(ForumGuestVisibility::INHERIT, $presets->presets([$forum])[$forum]);
        self::assertSame('name+threads', $sees([], $forum));

        $presets->apply($forum, ForumGuestVisibility::MEMBERS);
        self::assertSame(ForumGuestVisibility::MEMBERS, $presets->presets([$forum])[$forum]);
        self::assertSame('name', $sees([], $forum), 'guests see the name only');
        self::assertSame('name+threads', $sees([BuiltInGroup::Registered->value], $forum));

        $presets->apply($category, ForumGuestVisibility::HIDDEN);
        $presets->apply($forum, ForumGuestVisibility::INHERIT);
        self::assertSame('-', $sees([], $forum), 'the hidden parent is inherited');
        self::assertSame('-', $sees([BuiltInGroup::Registered->value], $forum));
        self::assertSame('name+threads', $sees(
            [BuiltInGroup::Registered->value, BuiltInGroup::Moderators->value],
            $forum,
        ), 'staff keep the hidden section');

        $presets->apply(null, ForumGuestVisibility::HIDDEN);
        $presets->apply($category, ForumGuestVisibility::INHERIT);
        self::assertSame(ForumGuestVisibility::HIDDEN, $presets->presets([])[0]);
        self::assertSame('-', $sees([], $forum), 'members-only board');
        self::assertSame('name+threads', $sees([BuiltInGroup::Registered->value], $forum));

        // A rule the presets do not describe makes the forum "custom" and survives an unrelated change.
        $rules->setGroupRule(BuiltInGroup::Guests->value, ForumVisibility::READ, 'never', $forum);
        self::assertSame(ForumGuestVisibility::CUSTOM, $presets->presets([$forum])[$forum]);
        $rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.thread.create', false, $forum);
        $presets->apply($forum, ForumGuestVisibility::PUBLIC);
        self::assertSame('0', $this->connection->fetchOne(
            'SELECT value FROM forum_permissions WHERE forum_id = ? AND usergroup_id = ? AND permission_key = ?',
            [$forum, BuiltInGroup::Registered->value, 'forum.thread.create'],
        ), 'other rules of the forum are kept');
        self::assertSame(ForumGuestVisibility::PUBLIC, $presets->presets([$forum])[$forum]);
        self::assertSame('name+threads', $sees([], $forum));
    }
}
