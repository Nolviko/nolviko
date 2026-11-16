<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Permissions;

use InvalidArgumentException;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Tests\Support\MemoryRuleStore;
use PHPUnit\Framework\TestCase;

/**
 * M02.09: the resolver applies ADR-011 to stored rules over a forum tree, explains each decision, memoizes per
 * request and resolves guests through the guests group.
 */
final class PermissionResolverTest extends TestCase
{
    private const VIP = 100;

    private MemoryRuleStore $store;

    private ArrayForumTree $forums;

    protected function setUp(): void
    {
        $this->store = new MemoryRuleStore(PermissionCatalog::core());
        // 1 General, 2 Staff (child 3 Staff archive), 4 VIP lounge.
        $this->forums = new ArrayForumTree([1 => null, 2 => null, 3 => 2, 4 => null]);
        $this->store->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', true);
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', true);
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, 2);
        $this->store->setGroupRule(BuiltInGroup::Moderators->value, 'forum.view', true, 2);
        $this->store->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, 4);
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, 4);
        $this->store->setGroupRule(self::VIP, 'forum.view', true, 4);
    }

    public function testForumVisibilityFollowsGroupsAndInheritance(): void
    {
        $resolver = $this->resolver();
        $guest = $resolver->guest();
        $member = $resolver->user(10, [BuiltInGroup::Registered->value]);
        $moderator = $resolver->user(11, [BuiltInGroup::Registered->value, BuiltInGroup::Moderators->value]);
        $vip = $resolver->user(12, [BuiltInGroup::Registered->value, self::VIP]);

        self::assertSame([1, 2, 3], $guest->forumsAllowing('forum.view'), 'guests: only the guests group rules');
        self::assertSame([1], $member->forumsAllowing('forum.view'));
        self::assertSame([1, 2, 3], $moderator->forumsAllowing('forum.view'), 'archive inherits from Staff');
        self::assertSame([1, 4], $vip->forumsAllowing('forum.view'));
    }

    public function testGuestsIgnoreOtherGroupsAndHaveNoOverrides(): void
    {
        $this->store->setUserRule(0, 'forum.thread.create', true);
        $guest = $this->resolver()->guest();

        self::assertSame([BuiltInGroup::Guests->value], $guest->groupIds);
        self::assertNull($guest->userId);
        self::assertFalse($guest->allows('forum.thread.create'));
    }

    public function testUserOverridesAndNever(): void
    {
        $this->store->setUserRule(12, 'forum.view', false, 4);
        $this->store->setUserRule(10, 'forum.view', true, 2);
        $this->store->setGroupRule(self::VIP, 'forum.post.reply', 'never');
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.reply', true);
        $resolver = $this->resolver();

        self::assertFalse($resolver->user(12, [2, self::VIP])->allows('forum.view', 4), 'user forum override');
        self::assertTrue($resolver->user(10, [2])->allows('forum.view', 3), 'user override inherits down the tree');
        self::assertFalse($resolver->user(13, [2, self::VIP])->allows('forum.post.reply'), 'never wins');
        self::assertTrue($resolver->user(14, [2])->allows('forum.post.reply'));
    }

    public function testTypedValuesTakeTheMostGenerousGroup(): void
    {
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.edit_window_minutes', 30);
        $this->store->setGroupRule(self::VIP, 'forum.post.edit_window_minutes', 240);
        $this->store->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'first_posts');
        $subject = $this->resolver()->user(12, [2, self::VIP]);

        self::assertSame(240, $subject->value('forum.post.edit_window_minutes'));
        self::assertSame('first_posts', $subject->value('forum.post.approval'));
        self::assertSame(3600, $subject->value('forum.post.flood_interval_seconds'), 'restrictive default');
    }

    public function testTraceExplainsTheDecision(): void
    {
        $trace = $this->resolver()->user(11, [2, 3])->explain('forum.view', 3);

        self::assertTrue($trace->decision->allowed());
        self::assertSame('group_forum', $trace->decision->reason);
        self::assertSame(1, $trace->decision->forumDepth, 'decided on the parent forum');
        self::assertContains(
            ['level' => 'group_forum', 'forum_id' => 2, 'source' => 'group:3', 'value' => '1'],
            $trace->rules,
        );
        self::assertContains(
            ['level' => 'group', 'forum_id' => null, 'source' => 'group:2', 'value' => '1'],
            $trace->rules,
        );
    }

    public function testRulesAreLoadedOncePerSubjectAndDecisionsMemoized(): void
    {
        $resolver = $this->resolver();
        $first = $resolver->user(10, [2]);
        $again = $resolver->user(10, [2]);

        foreach ([1, 2, 3, 4, 1, 2] as $forumId) {
            $first->allows('forum.view', $forumId);
        }

        self::assertSame($first, $again);
        self::assertSame($first->explain('forum.view', 1), $first->explain('forum.view', 1));
        self::assertSame(1, $this->store->loads);

        $resolver->guest();
        self::assertSame(2, $this->store->loads);
    }

    public function testUnknownKeysAreDeniedInProductionAndFailInDevelopment(): void
    {
        $production = $this->resolver()->guest();
        $trace = $production->explain('forum.no_such_key');

        self::assertFalse($trace->decision->allowed());
        self::assertSame(SubjectPermissions::UNKNOWN, $trace->decision->reason);

        $development = new PermissionResolver(PermissionCatalog::core(strict: true), $this->store, $this->forums);

        $this->expectException(PermissionError::class);
        $development->guest()->allows('forum.no_such_key');
    }

    public function testRuleWritesAreValidated(): void
    {
        $cases = [
            'never only for bool' => fn() => $this->store->setGroupRule(2, 'forum.post.approval', 'never'),
            'typed range' => fn() => $this->store->setGroupRule(2, 'forum.post.edit_window_minutes', 99999),
            'unknown key' => fn() => $this->store->setUserRule(1, 'forum.nope', true),
        ];

        foreach ($cases as $name => $write) {
            try {
                $write();
                self::fail($name);
            } catch (PermissionError $error) {
                self::assertMatchesRegularExpression('/^permission_(value_invalid|unknown):/', $error->diagnostic);
            }
        }
    }

    public function testForumTreeRefusesCyclesAndUnknownParents(): void
    {
        self::assertSame([3, 2], $this->forums->ancestry(3));
        self::assertSame([], $this->forums->ancestry(99));

        foreach ([[1 => 2, 2 => 1], [1 => 7]] as $parents) {
            try {
                new ArrayForumTree($parents);
                self::fail('accepted ' . json_encode($parents));
            } catch (InvalidArgumentException $failure) {
                self::assertStringStartsWith('forum_tree_invalid:', $failure->getMessage());
            }
        }
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(PermissionCatalog::core(), $this->store, $this->forums);
    }
}
