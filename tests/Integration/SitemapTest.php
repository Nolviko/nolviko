<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Http\FeedHandler;
use Nolviko\Modules\Seo\Http\SeoHandler;
use Nolviko\Modules\Seo\Infrastructure\Sitemaps;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SitemapTest extends TestCase
{
    private Connection $db;
    private TemporaryDirectory $files;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }
        $this->db = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        ));
        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('refusing installed forum');
        }
        TestDatabase::reset($this->db);
        $this->files = new TemporaryDirectory();
        $now = '2026-01-01 00:00:00.000000';
        foreach ([1, 2] as $id) {
            $this->db->insert('forums', ['id' => $id, 'type' => 'forum', 'title' => 'Forum ' . $id,
                'slug' => 'forum-' . $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->db->insert('forum_permissions', ['forum_id' => 2, 'usergroup_id' => 1,
            'permission_key' => 'forum.view', 'value' => 'never']);
        foreach (range(1, 5) as $id) {
            $this->db->insert('threads', ['id' => $id, 'forum_id' => $id === 2 ? 2 : 1,
                'title' => 'Thread ' . $id, 'slug' => 'thread-' . $id, 'status'
                    => $id === 3 ? 'pending' : 'visible',
                'created_at' => $now, 'updated_at' => $now]);
            $this->db->insert('posts', ['id' => $id, 'thread_id' => $id, 'content' => 'body',
                'content_format' => 'bbcode', 'status' => $id === 4 ? 'pending' : 'visible',
                'created_at' => $now, 'updated_at' => $now]);
            $this->db->update('threads', ['first_post_id' => $id], ['id' => $id]);
        }
        $this->db->update('threads', ['moved_to_thread_id' => 1], ['id' => 5]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            TestDatabase::release($this->db);
            $this->files->remove();
        }
    }

    private function maps(): Sitemaps
    {
        $catalog = PermissionCatalog::core(true);
        $permissions = new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        );
        $visibility = new ForumVisibility(
            new FileCache($this->files->path . '/cache', $this->files->path),
            fn(): int => (new VersionCounter($this->db))->current(VersionCounter::PERMISSION_RULES)
        );
        return new Sitemaps(
            $this->db,
            $visibility,
            $permissions->guest(...),
            new SiteUrl('https://forum.example.test'),
            RouterFactory::create(dirname(__DIR__, 2), $this->files->path)
        );
    }

    private function finish(): array
    {
        $this->maps()->active();
        for ($iteration = 0; $iteration < 10; ++$iteration) {
            $maps = $this->maps();
            (new CooperativeTaskRunner(
                $this->db,
                new OutboxQueue($this->db),
                ['seo.sitemap_requested' => $maps->build(...)],
                static fn(): bool => true
            ))->run(1, 5000);
            $run = $maps->active(false);
            if ($run !== null) {
                return $run;
            }
            $this->db->executeStatement('UPDATE outbox_events SET available_at = CURRENT_TIMESTAMP(6) '
                . '- INTERVAL \'1\' SECOND WHERE event_type = ?', ['seo.sitemap_requested']);
        }
        self::fail('durable generation did not complete');
    }

    public function testAtomFeedsListPublicThreadsWithPlainSummaries(): void
    {
        $this->db->executeStatement("UPDATE threads SET last_post_at = '2026-01-02 10:00:00.000000'");
        $this->db->update('posts', ['content' => '[b]Hello[/b] <world> & friends'], ['id' => 1]);
        $feed = function (array $parameters, ?string $site = 'https://forum.example.test') {
            $catalog = PermissionCatalog::core(true);
            $permissions = new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->db, $catalog),
                new DbalForumTree($this->db)
            );
            $visibility = new ForumVisibility(
                new FileCache($this->files->path . '/cache', $this->files->path),
                fn(): int => (new VersionCounter($this->db))->current(VersionCounter::PERMISSION_RULES)
            );

            return (new FeedHandler(
                $this->db,
                new DbalThreadRepository($this->db),
                new DbalForumRepository($this->db),
                $visibility,
                $permissions->guest(...),
                new SiteUrl($site),
                RouterFactory::create(dirname(__DIR__, 2), $this->files->path),
                TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en'),
            ))->handle(Request::create('/feed.xml'), $parameters);
        };

        $latest = $feed(['_route' => CoreRoutes::FEED_LATEST]);
        $xml = (string) $latest->getContent();
        self::assertSame(200, $latest->getStatusCode());
        self::assertSame('application/atom+xml; charset=UTF-8', $latest->headers->get('Content-Type'));
        self::assertNotFalse(simplexml_load_string($xml), 'well-formed XML');
        self::assertStringContainsString('<title>Latest discussions</title>', $xml);
        self::assertStringContainsString('<id>https://forum.example.test/threads/thread-1-1</id>', $xml);
        self::assertStringContainsString('<summary type="text">Hello &lt;world&gt; &amp; friends</summary>', $xml);
        foreach ([2, 3, 5] as $hidden) {
            self::assertStringNotContainsString('-' . $hidden . '</id>', $xml, 'guest-hidden or moved');
        }
        $forum = (string) $feed(['_route' => CoreRoutes::FEED_FORUM, 'forum' => '1'])->getContent();
        self::assertStringContainsString('<title>Forum 1</title>', $forum);
        self::assertStringContainsString('href="https://forum.example.test/forums/1/feed.xml"', $forum);
        self::assertSame(404, $feed(['_route' => CoreRoutes::FEED_FORUM, 'forum' => '2'])->getStatusCode());
        self::assertSame(404, $feed(['_route' => CoreRoutes::FEED_LATEST], null)->getStatusCode(), 'no site URL');
    }

    public function testCompleteOnlyPublicationRestartAndCurrentGuestPrivacy(): void
    {
        self::assertNull($this->maps()->index());
        self::assertNull($this->maps()->index());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_sitemap_runs'));
        $run = $this->finish();
        $maps = $this->maps();
        $index = $maps->index();
        self::assertStringContainsString('/sitemaps/' . $run['token'] . '/1.xml', $index);
        $page = $maps->threads($run['token'], 1);
        self::assertStringContainsString('<loc>https://forum.example.test/threads/thread-1-1</loc>', $page);
        foreach ([2, 3, 4, 5] as $hidden) {
            self::assertStringNotContainsString('-' . $hidden . '</loc>', $page);
        }
        self::assertStringContainsString('/forums/1</loc>', $maps->forums());
        self::assertStringNotContainsString('/forums/2</loc>', $maps->forums());
        self::assertNull($maps->threads(str_repeat('f', 32), 1));
        self::assertNull($maps->threads($run['token'], 0));
        $this->db->update('threads', ['status' => 'pending'], ['id' => 1]);
        self::assertStringNotContainsString('/threads/thread-1-1</loc>', $this->maps()->threads($run['token'], 1));
        $this->db->transactional(function (): void {
            $this->db->insert('forum_permissions', ['forum_id' => 1, 'usergroup_id' => 1,
                'permission_key' => 'forum.view', 'value' => 'never']);
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
        });
        self::assertNull($this->maps()->threads($run['token'], 1));
        self::assertNull($this->maps()->index());
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_sitemap_runs'));
    }

    public function testHttpResponsesRemainPrivateRegardlessOfMemberCookie(): void
    {
        $run = $this->finish();
        $handler = new SeoHandler(
            new SiteUrl('https://forum.example.test'),
            RouterFactory::create(dirname(__DIR__, 2), $this->files->path),
            fn(): Sitemaps => $this->maps()
        );
        $request = Request::create('/sitemaps/' . $run['token'] . '/1.xml', cookies: ['PHPSESSID'
            => 'member']);
        $response = $handler->handle($request, ['_route' => CoreRoutes::SITEMAP_THREADS,
            'generation' => $run['token'], 'page' => '1']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('-2</loc>', (string) $response->getContent());
    }

    public function testCapacityCannotCreateAnEndlessSeriesOfReplacementJobs(): void
    {
        $maps = $this->maps();
        $maps->active();
        $id = (int) $this->db->fetchOne('SELECT id FROM seo_sitemap_runs WHERE active_slot = 1');
        $this->db->update('seo_sitemap_runs', ['page_count' => Sitemaps::MAX_PAGES], ['id' => $id]);
        $lease = new OutboxLease(
            1,
            'seo.sitemap_requested',
            $id,
            '{"format":1}',
            str_repeat('a', 64),
            1,
            1
        );
        $this->db->transactional(fn(): bool => $maps->build($lease, static function (): void {
        }));
        self::assertSame(
            'capacity',
            $this->db->fetchOne('SELECT state FROM seo_sitemap_runs WHERE id = ?', [$id])
        );
        self::assertNull($this->maps()->index());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_sitemap_runs'));
    }

    public function testObsoletePageCleanupIsBounded(): void
    {
        $run = $this->finish();
        $this->db->update('seo_sitemap_runs', ['active_slot' => null], ['id' => $run['id']]);
        for ($ordinal = 2; $ordinal <= 101; ++$ordinal) {
            $this->db->insert('seo_sitemap_pages', ['run_id' => $run['id'], 'ordinal' => $ordinal,
                'first_id' => 1, 'last_id' => 5]);
        }
        $this->maps()->collect();
        self::assertSame(
            1,
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM seo_sitemap_pages WHERE run_id = ?',
                [$run['id']]
            )
        );
        $this->maps()->collect();
        $this->maps()->collect();
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_sitemap_runs WHERE id = ?', [$run['id']])
        );
    }
}
