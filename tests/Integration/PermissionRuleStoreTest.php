<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.09 on MySQL 8.4: seeded ADR-011 grants, the per-request query budget, validated writes, never, inherit
 * by deletion, and cascades.
 */
final class PermissionRuleStoreTest extends TestCase
{
    private Connection $connection;

    private DbalPermissionRuleStore $store;

    private int $userId;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->store = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $users = new DbalUserRepository($this->connection);
        $this->userId = $users->create('deniz', 'deniz@example.com', 'h', UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testSeededGrantsFollowAdr011(): void
    {
        $catalog = PermissionCatalog::core(strict: true);
        $resolver = new PermissionResolver($catalog, $this->store, new ArrayForumTree([]));
        $registered = BuiltInGroup::Registered->value;
        $guest = $resolver->guest();
        $member = $resolver->user($this->userId, [$registered]);
        $moderator = $resolver->user($this->userId, [$registered, BuiltInGroup::Moderators->value]);
        $admin = $resolver->user($this->userId, [$registered, BuiltInGroup::Administrators->value]);

        self::assertTrue($guest->allows('forum.view'));
        self::assertFalse($guest->allows('forum.thread.create'));
        self::assertTrue($member->allows('forum.thread.create'));
        // 0 = no time limit after migration 0050 (owner decision 2026-11-09).
        self::assertSame(0, $member->value('forum.post.edit_window_minutes'));
        self::assertSame('none', $member->value('forum.post.approval'));
        self::assertFalse($member->allows('moderation.post.hide'));
        self::assertTrue($moderator->allows('moderation.thread.lock'));
        self::assertFalse($moderator->allows('admin.access'));
        self::assertTrue($admin->allows('admin.access'));
        self::assertTrue($admin->allows('moderation.user.ban'));
    }

    public function testQueryBudgetIsThreeForAUserAndTwoForAGuestHoweverManyChecks(): void
    {
        $counter = new QueryCounter();
        $connection = self::connect([$counter]);
        // Connecting runs MysqlSession's one setup statement; count only what the permission code asks for.
        $connection->fetchOne('SELECT 1');
        $counter->count = 0;
        $store = new DbalPermissionRuleStore($connection, PermissionCatalog::core());
        $forums = new ArrayForumTree(array_fill_keys(range(1, 50), null));
        $resolver = new PermissionResolver(PermissionCatalog::core(), $store, $forums);
        $catalog = array_keys(PermissionCatalog::core()->all());

        $user = $resolver->user($this->userId, [BuiltInGroup::Registered->value, BuiltInGroup::Moderators->value]);

        foreach ($forums->forumIds() as $forumId) {
            foreach ($catalog as $key) {
                $user->value($key, $forumId);
            }
        }

        $afterUser = $counter->count;
        $resolver->guest()->forumsAllowing('forum.view');

        self::assertSame(3, $afterUser, '50 forums x ' . count($catalog) . ' keys');
        self::assertSame(5, $counter->count, 'a guest adds two');
        fwrite(STDERR, sprintf("permission_queries user=%d guest=%d\n", $afterUser, $counter->count - $afterUser));
    }

    public function testWritesValidateAndInheritIsDeletion(): void
    {
        $forum = $this->forum('lounge');
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.reply', 'never', $forum);
        $this->store->setUserRule($this->userId, 'forum.post.edit_window_minutes', 120);
        $this->store->setUserRule($this->userId, 'forum.view', false, $forum);
        $snapshot = $this->store->load([BuiltInGroup::Registered->value], $this->userId);

        self::assertSame('never', $snapshot->groupForum[$forum]['forum.post.reply'][BuiltInGroup::Registered->value]);
        self::assertSame('120', $snapshot->user['forum.post.edit_window_minutes']);
        self::assertSame('0', $snapshot->userForum[$forum]['forum.view']);

        $this->store->setUserRule($this->userId, 'forum.post.edit_window_minutes', null);
        self::assertArrayNotHasKey(
            'forum.post.edit_window_minutes',
            $this->store->load([], $this->userId)->user,
            'null removes the rule (inherit)',
        );

        foreach ([['forum.post.approval', 'never'], ['forum.view', 'maybe'], ['forum.nope', true]] as [$key, $value]) {
            try {
                $this->store->setGroupRule(BuiltInGroup::Registered->value, $key, $value);
                self::fail('accepted ' . $key);
            } catch (PermissionError $error) {
                self::assertStringStartsWith('permission_', $error->diagnostic);
            }
        }
    }

    public function testRulesGoWithTheirGroupOrUser(): void
    {
        $this->connection->insert('usergroups', [
            'id' => 150,
            'slug' => 'vip',
            'name' => 'VIP',
            'built_in' => 0,
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ]);
        $this->store->setGroupRule(150, 'forum.view', true, $this->forum('vip'));
        $this->store->setUserRule($this->userId, 'forum.view', true);

        $this->connection->delete('usergroups', ['id' => 150]);
        $this->connection->delete('users', ['id' => $this->userId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_permissions'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_permission_overrides'));
    }

    private function forum(string $slug): int
    {
        return (new DbalForumRepository($this->connection))->create(ForumType::Forum, $slug, $slug)->id;
    }

    /**
     * @param list<QueryCounter> $middlewares
     */
    private static function connect(array $middlewares = []): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ), $middlewares);
    }
}
