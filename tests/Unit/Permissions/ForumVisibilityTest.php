<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Permissions;

use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Tests\Support\MemoryRuleStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * M02.10: visible and readable forum sets, shared per group combination through the cache and invalidated by
 * the rules version; personal overrides never share; SQL scope for empty and filled sets.
 */
final class ForumVisibilityTest extends TestCase
{
    private const REGISTERED = BuiltInGroup::Registered->value;

    private TemporaryDirectory $storage;

    private MemoryRuleStore $store;

    private int $version = 1;

    protected function setUp(): void
    {
        $this->storage = new TemporaryDirectory();
        $this->store = new MemoryRuleStore(PermissionCatalog::core());
        $this->store->setGroupRule(self::REGISTERED, 'forum.view', true);
        $this->store->setGroupRule(self::REGISTERED, 'forum.thread.view', true);
        $this->store->setGroupRule(self::REGISTERED, 'forum.view', false, 2);
        $this->store->setGroupRule(self::REGISTERED, 'forum.thread.view', false, 3);
    }

    protected function tearDown(): void
    {
        $this->storage->remove();
    }

    public function testVisibleAndReadableSets(): void
    {
        $subject = $this->resolver()->user(10, [self::REGISTERED]);
        $visibility = $this->visibility();

        self::assertSame([1, 3], $visibility->visible($subject)->forumIds, '2 is hidden and 4 inherits that');
        self::assertSame([1], $visibility->readable($subject)->forumIds, '3 is listed but its threads are not');
    }

    public function testSameGroupsShareTheCachedSetUntilTheRulesVersionChanges(): void
    {
        $this->visibility()->visible($this->resolver()->user(10, [self::REGISTERED]));

        // Rules change without a version bump: another member still gets the shared cached set.
        $this->store->setGroupRule(self::REGISTERED, 'forum.view', false, 1);
        $stale = $this->visibility()->visible($this->resolver()->user(11, [self::REGISTERED]));
        ++$this->version;
        $fresh = $this->visibility()->visible($this->resolver()->user(11, [self::REGISTERED]));

        self::assertSame([1, 3], $stale->forumIds);
        self::assertSame([3], $fresh->forumIds);
    }

    public function testPersonalOverridesAreNeverSharedOrCached(): void
    {
        $this->visibility()->visible($this->resolver()->user(10, [self::REGISTERED]));
        $this->store->setUserRule(12, 'forum.view', true, 2);

        $personal = $this->visibility()->visible($this->resolver()->user(12, [self::REGISTERED]));
        $other = $this->visibility()->visible($this->resolver()->user(13, [self::REGISTERED]));

        self::assertSame([1, 2, 3, 4], $personal->forumIds, 'the override on 2 also opens its child 4');
        self::assertSame([1, 3], $other->forumIds, 'the personal set did not leak into the shared cache');
    }

    public function testScopeSql(): void
    {
        self::assertSame(['1 = 0', [], []], ScopeSql::forums(new ForumScope([]), 't.forum_id'));

        [$condition, $parameters] = ScopeSql::forums(new ForumScope([1, 3]), 't.forum_id');

        self::assertSame('t.forum_id IN (?)', $condition);
        self::assertSame([[1, 3]], $parameters);
        self::assertTrue((new ForumScope([1, 3]))->contains(3));
        self::assertFalse((new ForumScope([1, 3]))->contains(2));
    }

    private function resolver(): PermissionResolver
    {
        // 1 open, 2 hidden (child 4 inherits), 3 listed but its threads not readable.
        $forums = new ArrayForumTree([1 => null, 2 => null, 3 => null, 4 => 2]);

        return new PermissionResolver(PermissionCatalog::core(), $this->store, $forums);
    }

    private function visibility(): ForumVisibility
    {
        $cache = (new CacheFactory($this->storage->path))->namespace('permissions');

        return new ForumVisibility($cache, fn(): int => $this->version);
    }
}
