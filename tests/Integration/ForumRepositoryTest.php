<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.01 on MySQL 8.4: the adjacency-list tree (ADR-027), move constraints, depth limit and delete behaviour.
 */
final class ForumRepositoryTest extends TestCase
{
    private Connection $connection;

    private DbalForumRepository $forums;

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
        $this->forums = new DbalForumRepository($this->connection, maxDepth: 3);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testTreeIsReadParentsFirstInSortOrderWithDepth(): void
    {
        $general = $this->forums->create(ForumType::Category, 'General', 'general', sortOrder: 2);
        $staff = $this->forums->create(ForumType::Category, 'Staff', 'staff', sortOrder: 1);
        $news = $this->forums->create(ForumType::Forum, 'News', 'news', $general->id, 2);
        $chat = $this->forums->create(ForumType::Forum, 'Chat', 'chat', $general->id, 1);
        $archive = $this->forums->create(ForumType::Forum, 'Archive', 'archive', $chat->id);

        self::assertSame(
            [[$staff->id, 0], [$general->id, 0], [$chat->id, 1], [$archive->id, 2], [$news->id, 1]],
            array_map(static fn(Forum $forum): array => [$forum->id, $forum->depth], $this->forums->all()),
        );
        self::assertSame(ForumType::Category, $staff->type);
        $tree = new DbalForumTree($this->connection);

        self::assertSame([$archive->id, $chat->id, $general->id], $tree->ancestry($archive->id));
    }

    public function testMovesIntoTheOwnSubtreeAreRejected(): void
    {
        $root = $this->forums->create(ForumType::Forum, 'Root', 'root');
        $child = $this->forums->create(ForumType::Forum, 'Child', 'child', $root->id);
        $grandchild = $this->forums->create(ForumType::Forum, 'Grandchild', 'grandchild', $child->id);

        $moves = [[$root->id, $grandchild->id], [$root->id, $root->id], [$child->id, $grandchild->id]];

        foreach ($moves as [$id, $parent]) {
            try {
                $this->forums->move($id, $parent);
                self::fail('moved ' . $id . ' under ' . $parent);
            } catch (ForumRuleViolation $violation) {
                self::assertSame('forum_move_into_descendant', $violation->diagnostic);
            }
        }

        self::assertSame($root->id, $this->forums->find($child->id)?->parentId, 'nothing changed');
    }

    public function testMovingUpdatesTheDepthOfTheWholeSubtree(): void
    {
        $a = $this->forums->create(ForumType::Forum, 'A', 'a');
        $b = $this->forums->create(ForumType::Forum, 'B', 'b');
        $a1 = $this->forums->create(ForumType::Forum, 'A1', 'a1', $a->id);
        $a2 = $this->forums->create(ForumType::Forum, 'A2', 'a2', $a1->id);

        $this->forums->move($a->id, $b->id, 5);

        self::assertSame([1, 2, 3], [
            $this->forums->find($a->id)?->depth,
            $this->forums->find($a1->id)?->depth,
            $this->forums->find($a2->id)?->depth,
        ]);
        self::assertSame(5, $this->forums->find($a->id)?->sortOrder);

        $this->forums->move($a1->id, null);
        self::assertSame([0, 1], [$this->forums->find($a1->id)?->depth, $this->forums->find($a2->id)?->depth]);
    }

    public function testMaximumDepthIsEnforcedOnCreateAndMove(): void
    {
        $level0 = $this->forums->create(ForumType::Forum, 'L0', 'l0');
        $level1 = $this->forums->create(ForumType::Forum, 'L1', 'l1', $level0->id);
        $level2 = $this->forums->create(ForumType::Forum, 'L2', 'l2', $level1->id);
        $level3 = $this->forums->create(ForumType::Forum, 'L3', 'l3', $level2->id);
        $other = $this->forums->create(ForumType::Forum, 'Other', 'other');
        $otherChild = $this->forums->create(ForumType::Forum, 'Other child', 'other-child', $other->id);

        $attempts = [
            'create below the limit' => fn() => $this->forums->create(ForumType::Forum, 'L4', 'l4', $level3->id),
            'move a subtree too deep' => fn() => $this->forums->move($other->id, $level2->id),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail($name);
            } catch (ForumRuleViolation $violation) {
                self::assertSame('forum_too_deep', $violation->diagnostic, $name);
            }
        }

        $this->forums->move($otherChild->id, $level2->id);
        self::assertSame(3, $this->forums->find($otherChild->id)?->depth, 'exactly the limit is fine');
    }

    public function testDeleteRestrictsParentsAndCascadesForumRules(): void
    {
        $parent = $this->forums->create(ForumType::Category, 'Parent', 'parent');
        $child = $this->forums->create(ForumType::Forum, 'Child', 'child', $parent->id);
        $rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $child->id);

        try {
            $this->forums->delete($parent->id);
            self::fail('deleted a forum with subforums');
        } catch (ForumRuleViolation $violation) {
            self::assertSame('forum_has_children', $violation->diagnostic);
        }

        $this->forums->delete($child->id);
        $this->forums->delete($parent->id);

        self::assertSame([], $this->forums->all());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_permissions'));
    }

    public function testSlugsAndParentsAreValidated(): void
    {
        $this->forums->create(ForumType::Forum, 'News', 'news');
        $cases = [
            'forum_slug_taken' => fn() => $this->forums->create(ForumType::Forum, 'News 2', 'news'),
            'forum_slug_invalid' => fn() => $this->forums->create(ForumType::Forum, 'Bad', 'Bad Slug'),
            'forum_parent_not_found' => fn() => $this->forums->create(ForumType::Forum, 'Orphan', 'orphan', 999),
            'forum_not_found' => fn() => $this->forums->move(999, null),
        ];

        foreach ($cases as $diagnostic => $attempt) {
            try {
                $attempt();
                self::fail($diagnostic);
            } catch (ForumRuleViolation $violation) {
                self::assertSame($diagnostic, $violation->diagnostic);
            }
        }
    }
}
