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
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\ThreadViews;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Thread view counts (2026-11-08): browsers count, crawlers and prefetch do not, a member counts once per session,
 * and the lists carry the number.
 */
final class ThreadViewsTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36';

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

    public function testBrowsersCountCrawlersDoNotAndMembersCountOncePerSession(): void
    {
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Forum', 'forum')->id;
        $threads = new DbalThreadRepository($this->connection);
        $thread = $threads->create(
            $forum,
            null,
            'Counted',
            'counted',
            new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC')),
        );
        $views = new ThreadViews($this->connection, new ArraySession());

        $views->record(self::BROWSER, '', $thread, null);
        $views->record(self::BROWSER, '', $thread, null);
        $views->record('Googlebot/2.1 (+http://www.google.com/bot.html)', '', $thread, null);
        $views->record('facebookexternalhit/1.1', '', $thread, null);
        $views->record('', '', $thread, null);
        $views->record(self::BROWSER, 'prefetch', $thread, null);
        $views->record(self::BROWSER, '', $thread, 7);
        $views->record(self::BROWSER, '', $thread, 7);

        self::assertSame(3, $this->viewsOf($thread), 'two guest views and one member view');
        self::assertSame(3, $threads->forumThreads(new ForumScope([$forum]), $forum, 5)[0]->viewCount);
        self::assertSame(3, $threads->latest(new ForumScope([$forum]), 5)[0]->viewCount);

        (new ThreadViews($this->connection, new ArraySession()))->record(self::BROWSER, '', $thread, 7);
        self::assertSame(4, $this->viewsOf($thread), 'a new session counts again');
    }

    private function viewsOf(int $thread): int
    {
        return (int) $this->connection->fetchOne('SELECT view_count FROM threads WHERE id = ?', [$thread]);
    }
}
