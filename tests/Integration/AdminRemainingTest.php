<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorAssignments;
use Nolviko\Modules\Admin\Infrastructure\SavedAdminFilters;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumModeratorCapabilities;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
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
final class AdminRemainingTest extends TestCase
{
    private Connection $connection;

    private DbalPermissionRuleStore $rules;

    private int $member;

    private int $admin;

    private int $other;

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
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $users = new DbalUserRepository($this->connection);
        $this->member = $users->create('Member', 'member@example.com', 'h', UserStatus::Active)->id;
        $this->admin = $users->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        $this->other = $users->create('Other', 'other@example.com', 'h', UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testSavedFiltersArePrivateCappedNormalizedAndAudited(): void
    {
        $service = new SavedAdminFilters($this->connection);
        $admin = $this->subject($this->admin);
        $other = $this->subject($this->other);
        $id = $service->save($admin, 'Ｆｉｌｔｅｒ', 'members', ['name' => 'Member', 'before' => '45']);
        self::assertCount(1, $service->all($admin));
        self::assertSame([], $service->all($other));
        self::assertFalse($service->remove($other, $id));
        self::assertSame('{"name":"Member"}', $service->all($admin)[0]['filters_json']);
        try {
            $service->save($admin, 'filter', 'settings', []);
            self::fail('normalized duplicate accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('admin_filter_name_taken', $error->getMessage());
        }
        for ($n = 1; $n < 30; ++$n) {
            $service->save($admin, 'Filter ' . $n, 'audit', ['family' => 'admin']);
        }
        try {
            $service->save($admin, 'overflow', 'settings', []);
            self::fail('cap ignored');
        } catch (InvalidArgumentException $error) {
            self::assertSame('admin_filter_limit', $error->getMessage());
        }
        self::assertTrue($service->remove($admin, $id));
        self::assertSame(31, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin.saved_filter_%'",
        ));
        $this->connection->delete('users', ['id' => $this->admin]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_saved_filters'));
    }

    public function testLocalAssignmentsFollowAdr011AndExplainTheirSource(): void
    {
        $forums = new DbalForumRepository($this->connection);
        $parent = $forums->create(ForumType::Forum, 'Parent', 'parent')->id;
        $child = $forums->create(ForumType::Forum, 'Child', 'child', $parent)->id;
        $outside = $forums->create(ForumType::Forum, 'Outside', 'outside')->id;
        $service = new ForumModeratorAssignments($this->connection);
        $admin = $this->subject($this->admin);
        $before = (int) $this->connection->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = 'permissions.rules_version'",
        );
        self::assertTrue($service->assign($admin, true, $parent, 'Member', null));
        self::assertFalse($service->assign($admin, true, $parent, 'Member', null));
        $subject = $this->subject($this->member);
        foreach (ForumModeratorCapabilities::KEYS as $key) {
            self::assertTrue($subject->allows($key, $parent));
            self::assertTrue($subject->allows($key, $child));
            self::assertFalse($subject->allows($key, $outside));
            self::assertFalse($subject->allows($key));
        }
        self::assertTrue($subject->hasUserOverrides());
        self::assertFalse($subject->allows('admin.access'));
        self::assertFalse($subject->allows('moderation.user.ban', $parent));
        self::assertSame('forum_moderator:user', $subject->explain('moderation.post.hide', $child)->rules[0]['source']);
        $this->rules->setUserRule($this->member, 'moderation.post.hide', false);
        self::assertTrue($this->subject($this->member)->allows('moderation.post.hide', $child), 'L1 beats L2 deny');
        $this->rules->setUserRule($this->member, 'moderation.post.hide', false, $child);
        self::assertFalse($this->subject($this->member)->allows('moderation.post.hide', $child), 'nearer L1 wins');
        $this->rules->setUserRule($this->member, 'moderation.post.hide', null, $child);
        $this->rules->setGroupRule(2, 'moderation.post.hide', 'never');
        self::assertFalse($this->subject($this->member)->allows('moderation.post.hide', $child), 'never wins');
        $this->rules->setGroupRule(2, 'forum.view', 'never', $parent);
        self::assertFalse(
            $this->subject($this->member)->allows('forum.view', $child),
            'assignment grants no visibility',
        );
        $id = (int) $this->connection->fetchOne('SELECT id FROM forum_moderators WHERE user_id = ?', [$this->member]);
        self::assertTrue($service->remove($admin, true, $id));
        self::assertFalse($service->remove($admin, true, $id));
        self::assertFalse($this->subject($this->member)->allows('moderation.thread.lock', $parent));
        self::assertGreaterThan($before, (int) $this->connection->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = 'permissions.rules_version'",
        ));
    }

    public function testGroupAssignmentDoesNotOverrideUserDenyAndCanBeSuppressedLocally(): void
    {
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Local', 'local')->id;
        $service = new ForumModeratorAssignments($this->connection);
        self::assertTrue($service->assign($this->subject($this->admin), true, $forum, '', 2));
        self::assertTrue($this->subject($this->member)->allows('moderation.post.hide', $forum));
        self::assertStringStartsWith(
            'forum_moderator:group:',
            $this->subject($this->member)->explain('moderation.post.hide', $forum)->rules[0]['source']
        );
        $this->rules->setUserRule($this->member, 'moderation.post.hide', false);
        self::assertFalse($this->subject($this->member)->allows('moderation.post.hide', $forum));
        $this->rules->setUserRule($this->member, 'moderation.post.hide', null);
        $this->rules->setGroupRule(2, 'moderation.post.hide', false, $forum);
        self::assertFalse($this->subject($this->member)->allows('moderation.post.hide', $forum));
        self::assertSame(
            'group:2',
            $this->subject($this->member)->explain('moderation.post.hide', $forum)->rules[0]['source'],
        );
        $this->rules->setGroupRule(2, 'moderation.post.hide', null, $forum);
        self::assertTrue($this->subject($this->member)->allows('moderation.post.hide', $forum));
        $this->connection->delete('forums', ['id' => $forum]);
        self::assertSame([], $service->page($this->subject($this->admin)));
    }

    public function testMutationsRejectMembersStaleAdminAndGuests(): void
    {
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Local', 'local')->id;
        $service = new ForumModeratorAssignments($this->connection);
        foreach ([[$this->member, true], [$this->admin, false]] as [$actor, $fresh]) {
            try {
                $service->assign($this->subject($actor), $fresh, $forum, 'Member', null);
                self::fail('unauthorized assignment accepted');
            } catch (PermissionError) {
                self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_moderators'));
            }
        }
        foreach ([['', 1], ['Member', 2], ['', null], ['missing', null]] as [$member, $group]) {
            try {
                $service->assign($this->subject($this->admin), true, $forum, $member, $group);
                self::fail('invalid target accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_moderators'));
            }
        }
        try {
            (new SavedAdminFilters($this->connection))->save($this->subject($this->member), 'Private', 'settings', []);
            self::fail('non-admin saved preferences');
        } catch (PermissionError) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_saved_filters'));
        }
    }

    private function subject(int $user): SubjectPermissions
    {
        return (new PermissionResolver(
            PermissionCatalog::core(),
            $this->rules,
            new DbalForumTree($this->connection)
        ))->user(
            $user,
            [$user === $this->member ? BuiltInGroup::Registered->value : BuiltInGroup::Administrators->value]
        );
    }

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
