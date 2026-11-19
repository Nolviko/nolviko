<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Modules\Api\Http\ApiHandler;
use Nolviko\Modules\Api\Infrastructure\Webhooks;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumIndex;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Infrastructure\IndexNow;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only JSON API and outgoing webhooks (2026-11-08): guest view only, signed once-per-event delivery, no
 * private forums and no private addresses.
 */
final class ApiAndWebhooksTest extends TestCase
{
    private Connection $connection;

    private TemporaryDirectory $storage;

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
        $this->storage = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
            $this->storage->remove();
        }
    }

    public function testApiShowsWhatGuestsSeeAndWebhooksAreSignedOnce(): void
    {
        $alice = (new DbalUserRepository($this->connection))
            ->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $forums = new DbalForumRepository($this->connection);
        $open = $forums->create(ForumType::Forum, 'Open', 'open')->id;
        $staff = $forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $catalog = PermissionCatalog::core();
        $rules = new DbalPermissionRuleStore($this->connection, $catalog);
        $rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $staff);
        $threads = new DbalThreadRepository($this->connection);
        $posts = new DbalPostRepository($this->connection);
        $at = new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC'));
        $public = $threads->create($open, $alice, 'Hello', 'hello', $at);
        $publicPost = $posts->add($public, $alice, '[b]Hi[/b] all', 'bbcode', $at);
        $hidden = $threads->create($staff, $alice, 'Secret', 'secret', $at);
        $hiddenPost = $posts->add($hidden, $alice, 'Plans', 'bbcode', $at);
        $this->connection->executeStatement('UPDATE threads SET last_post_at = created_at, reply_count = 0, '
            . 'first_post_id = (SELECT MIN(id) FROM posts WHERE posts.thread_id = threads.id)');
        $resolver = new PermissionResolver($catalog, $rules, new DbalForumTree($this->connection));
        $visibility = new ForumVisibility(
            new FileCache($this->storage->path . '/cache', $this->storage->path),
            fn(): int => (new VersionCounter($this->connection))->current(VersionCounter::PERMISSION_RULES),
        );
        $connection = $this->connection;
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $api = new ApiHandler(
            new DbalForumIndex($this->connection),
            $visibility,
            $resolver->guest(...),
            $threads,
            $posts,
            new PostRenderer($posts),
            $router,
            new SiteUrl('https://forum.example'),
            new SiteSettings(
                static fn(): Connection => $connection,
                (new CacheFactory($this->storage->path))->namespace('settings'),
            ),
            TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en'),
        );
        $get = static fn(string $route, array $parameters = []) => json_decode((string) $api->handle(
            Request::create('/api'),
            ['_route' => $route] + $parameters,
        )->getContent(), true);

        self::assertSame(['Open'], array_column($get(CoreRoutes::API_FORUMS)['data'], 'title'));
        self::assertSame(['Hello'], array_column($get(CoreRoutes::API_THREADS)['data'], 'title'));
        self::assertSame('not_found', $get(CoreRoutes::API_THREAD, ['thread' => (string) $hidden])['error']);
        $thread = $get(CoreRoutes::API_THREAD, ['thread' => (string) $public])['data'];
        // The canonical `/threads/{slug}-{id}` address (2026-11-14).
        $slug = (string) $this->connection->fetchOne('SELECT slug FROM threads WHERE id = ?', [$public]);
        $canonical = 'https://forum.example/threads/' . $slug . '-' . $public;
        self::assertSame($canonical, $thread['url']);
        self::assertStringContainsString('<strong>Hi</strong>', $thread['posts'][0]['html']);
        self::assertSame('*', $api->handle(Request::create('/api'), ['_route' => CoreRoutes::API_FORUMS])
            ->headers->get('Access-Control-Allow-Origin'));

        $sent = [];
        $hooks = new Webhooks(
            $this->connection,
            static fn() => $visibility->readable($resolver->guest()),
            'https://forum.example',
            static function (string $url, string $body, array $headers, string $ip) use (&$sent): int {
                $sent[] = [$url, $body, $headers];
                self::assertSame('93.184.215.14', $ip);

                return 200;
            },
            static fn(string $host): array => $host === 'dual.example'
                ? [['ip' => '93.184.215.14'], ['ipv6' => '::1']]
                : [['ip' => '93.184.215.14']],
        );

        foreach (
            ['http://hooks.example/x', 'https://127.0.0.1/x', 'https://localhost/x', 'https://[::1]/x',
            'https://224.0.0.1/x', 'https://[ff02::1]/x'] as $bad
        ) {
            try {
                $hooks->add($bad, ['post.created']);
                self::fail('refused: ' . $bad);
            } catch (RegistrationRejected) {
                self::assertTrue(true);
            }
        }

        $hooks->add('https://dual.example/hook', ['post.created']);
        $secret = $hooks->add('https://hooks.example/hook', ['post.created']);
        $lease = static fn(int $id, int $post): OutboxLease => new OutboxLease($id, 'post.created', $post, json_encode(
            ['post_id' => $post, 'thread_id' => 0, 'user_id' => $alice, 'status' => 'visible'],
            JSON_THROW_ON_ERROR,
        ), str_repeat('a', 64), 1, 1);
        $hooks->deliver($lease(501, $publicPost));
        $hooks->deliver($lease(501, $publicPost));
        $hooks->deliver($lease(502, $hiddenPost));

        self::assertCount(1, $sent, 'once per event; nothing from a forum guests cannot read');
        [$url, $body, $headers] = $sent[0];
        self::assertSame('https://hooks.example/hook', $url);
        self::assertSame('sha256=' . hash_hmac('sha256', $body, $secret), $headers['X-Nolviko-Signature']);
        self::assertSame('Hello', json_decode($body, true)['thread']['title']);
        self::assertSame(200, (int) $this->connection->fetchOne(
            'SELECT last_status FROM webhooks WHERE url = ?',
            ['https://hooks.example/hook']
        ));

        $pinged = [];
        $indexNow = new IndexNow(
            $this->connection,
            static fn() => $visibility->readable($resolver->guest()),
            'https://forum.example',
            IndexNow::key('secret'),
            static function (string $url) use (&$pinged): int {
                $pinged[] = $url;

                return 200;
            },
        );
        $thread = static fn(int $id): OutboxLease => new OutboxLease(600 + $id, 'thread.created', $id, json_encode(
            ['thread_id' => $id],
            JSON_THROW_ON_ERROR,
        ), str_repeat('b', 64), 1, 1);
        $indexNow->submit($thread($public));
        self::assertSame([], $pinged, 'off until the administrator turns it on');
        $this->connection->insert('settings', ['setting_key' => 'seo.indexnow', 'setting_value' => 'on',
            'value_type' => 'string', 'updated_at' => '2026-11-08 00:00:00.000000']);
        $indexNow->submit($thread($public));
        $indexNow->submit($thread($hidden));
        self::assertCount(1, $pinged, 'only public threads');
        self::assertStringContainsString('url=' . rawurlencode($canonical), $pinged[0]);
        self::assertStringContainsString('key=' . IndexNow::key('secret'), $pinged[0]);
        $this->connection->executeStatement('DELETE FROM settings');
    }
}
