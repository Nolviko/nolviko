<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Thread prefixes and tags (2026-11-08): forum-bound and board-wide prefixes, validated choices, labels for a
 * page of threads in two queries, the tag filter of the latest list, and audited administrator changes.
 */
final class ThreadTaxonomyTest extends TestCase
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

    public function testPrefixesTagsAndTheTagFilter(): void
    {
        $admin = (new DbalUserRepository($this->connection))
            ->create('admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        $forums = new DbalForumRepository($this->connection);
        $news = $forums->create(ForumType::Forum, 'News', 'news')->id;
        $help = $forums->create(ForumType::Forum, 'Help', 'help')->id;
        $threads = new DbalThreadRepository($this->connection);
        $at = new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC'));
        $first = $threads->create($news, $admin, 'First', 'first', $at);
        $second = $threads->create($news, $admin, 'Second', 'second', $at->modify('+1 minute'));
        $this->connection->executeStatement('UPDATE threads SET last_post_at = created_at');
        $taxonomy = new ThreadTaxonomy($this->connection);

        $taxonomy->createPrefix('Solved', 'accent', null, $admin);
        $taxonomy->createPrefix('Bug', 'danger', $help, $admin);
        $taxonomy->createTag('Release Notes', $admin);
        $taxonomy->createTag('PHP', $admin);
        self::assertSame('taxonomy_tag_taken', self::refused(
            static fn() => $taxonomy->createTag('release notes', $admin),
        ));
        self::assertSame('taxonomy_color_invalid', self::refused(
            static fn() => $taxonomy->createPrefix('X', 'red; background:url(x)', null, $admin),
        ));
        self::assertSame('taxonomy_label_invalid', self::refused(
            static fn() => $taxonomy->createTag(str_repeat('a', 41), $admin),
        ));

        $solved = $taxonomy->prefixes($news)[0];
        $bug = $taxonomy->prefixes($help)[0]['label'] === 'Bug' ? $taxonomy->prefixes($help)[0]
            : $taxonomy->prefixes($help)[1];
        $tags = array_column($taxonomy->tags(), 'id', 'slug');
        self::assertSame(['Solved'], array_column($taxonomy->prefixes($news), 'label'), 'help prefixes stay in help');
        self::assertSame('chip chip-new', $solved['class']);
        self::assertSame(['php', 'release-notes'], array_keys($tags));
        self::assertSame('thread_prefix_invalid', self::refused(
            static fn() => $taxonomy->validate($news, $bug['id'], []),
        ));
        self::assertSame('thread_tags_invalid', self::refused(
            static fn() => $taxonomy->validate($news, null, [999]),
        ));

        $taxonomy->assign($first, $news, $solved['id'], [$tags['php'], $tags['release-notes']], $admin);
        $taxonomy->assign($second, $news, null, [$tags['php']]);
        $labels = $taxonomy->forThreads([$first, $second]);

        self::assertSame('Solved', $labels[$first]['prefix']['label'] ?? null);
        self::assertSame(['PHP', 'Release Notes'], array_column($labels[$first]['tags'], 'name'));
        self::assertNull($labels[$second]['prefix']);
        $scope = new ForumScope([$news, $help]);
        self::assertSame([$second, $first], array_map(
            static fn($item): int => $item->id,
            $threads->latest($scope, 10, null, $tags['php']),
        ));
        self::assertSame([$first], array_map(
            static fn($item): int => $item->id,
            $threads->latest($scope, 10, null, $tags['release-notes']),
        ));
        self::assertSame([], $threads->latest(new ForumScope([$help]), 10, null, $tags['php']), 'scope still applies');

        $taxonomy->delete('tag', $tags['php'], $admin);
        self::assertSame(['Release Notes'], array_column($taxonomy->forThreads([$first])[$first]['tags'], 'name'));
        self::assertSame(6, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'taxonomy.%' OR action = 'thread.taxonomy'",
        ), 'four creations, one moderator assignment, one deletion');
    }

    private static function refused(callable $action): string
    {
        try {
            $action();
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        return 'accepted';
    }
}
