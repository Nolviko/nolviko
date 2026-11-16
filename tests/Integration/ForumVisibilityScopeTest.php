<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.10 on MySQL 8.4 with real forums and rules: hidden forums are filtered in SQL before LIMIT and COUNT,
 * and every rule or tree change bumps the rules version that keys the shared visibility cache.
 */
final class ForumVisibilityScopeTest extends TestCase
{
    private Connection $connection;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

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
        $this->forums = new DbalForumRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testHiddenForumsNeverReachListsOrCounts(): void
    {
        $ids = [];

        foreach (['a', 'b', 'staff', 'c', 'd'] as $order => $slug) {
            $ids[$slug] = $this->forums->create(ForumType::Forum, strtoupper($slug), $slug, sortOrder: $order)->id;
        }

        $staffChild = $this->forums->create(ForumType::Forum, 'Staff archive', 'staff-archive', $ids['staff'])->id;
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $ids['staff']);
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, 'forum.view', true, $ids['staff']);

        $guestScope = $this->visibility()->visible($this->resolver()->guest());
        $moderator = $this->resolver()->user(1, [BuiltInGroup::Registered->value, BuiltInGroup::Moderators->value]);
        $moderatorScope = $this->visibility()->visible($moderator);
        $slugs = static fn(array $forums): array => array_map(
            static fn(Forum $forum): string => $forum->slug,
            $forums,
        );

        self::assertSame(4, $this->forums->countIn($guestScope), 'the hidden forum and its child are not counted');
        self::assertSame(['a', 'b'], $slugs($this->forums->listIn($guestScope, 2)));
        self::assertSame(['c', 'd'], $slugs($this->forums->listIn($guestScope, 2, 2)), 'LIMIT after the filter');
        self::assertFalse($guestScope->contains($staffChild), 'inherited from the hidden parent');
        self::assertSame(6, $this->forums->countIn($moderatorScope), 'moderators also see the staff forums');
    }

    public function testRuleAndTreeChangesBumpTheVersion(): void
    {
        $counter = new VersionCounter($this->connection);
        $start = $counter->current(VersionCounter::PERMISSION_RULES);

        $forum = $this->forums->create(ForumType::Forum, 'X', 'x')->id;
        $afterCreate = $counter->current(VersionCounter::PERMISSION_RULES);
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $forum);
        $afterRule = $counter->current(VersionCounter::PERMISSION_RULES);
        $this->forums->move($forum, null, 3);
        $this->forums->delete($forum);

        $end = $counter->current(VersionCounter::PERMISSION_RULES);

        self::assertSame([0, 1, 2, 4], [$start, $afterCreate, $afterRule, $end]);
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(
            PermissionCatalog::core(),
            $this->rules,
            new DbalForumTree($this->connection),
        );
    }

    private function visibility(): ForumVisibility
    {
        $storage = new TemporaryDirectory();
        $cache = (new CacheFactory($storage->path))->namespace('permissions');
        $counter = new VersionCounter($this->connection);

        return new ForumVisibility($cache, static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES));
    }
}
