<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AuditLogHandler;
use Nolviko\Modules\Admin\Infrastructure\DbalAuditLog;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.10: the audit log reads newest first in keyset pages of 50, filters by family, actor and target, ignores
 * malformed filters, escapes every value and costs one statement per page (two with an actor name).
 */
final class AuditLogTest extends TestCase
{
    private Connection $connection;

    private int $mod;

    private int $admin;

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
        $users = new DbalUserRepository($this->connection);
        $this->mod = $users->create('Mod', 'mod@example.com', 'h', UserStatus::Active)->id;
        $this->admin = $users->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;

        foreach (range(1, 120) as $i) {
            $this->connection->insert('audit_logs', [
                'actor_id' => $i % 2 === 0 ? $this->mod : $this->admin,
                'action' => ['post.delete', 'thread.move', 'user.ban'][$i % 3],
                'target_type' => ['post', 'thread', 'user'][$i % 3],
                'target_id' => $i,
                'reason' => $i === 120 ? '<script>alert(1)</script>' : 'r' . $i,
                'details' => $i === 120 ? '{"note":"<b>x</b>"}' : null,
                'created_at' => sprintf('2026-11-02 10:%02d:%02d.000000', intdiv($i, 60), $i % 60),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testKeysetPagesNewestFirst(): void
    {
        $log = new DbalAuditLog($this->connection);
        $first = $log->page([]);
        $second = $log->page([], $first[DbalAuditLog::PAGE_SIZE - 1]['id']);
        $third = $log->page([], $second[DbalAuditLog::PAGE_SIZE - 1]['id']);

        self::assertCount(DbalAuditLog::PAGE_SIZE + 1, $first, 'one extra row tells that an older page exists');
        self::assertSame(120, $first[0]['target_id']);
        self::assertSame(71, $first[DbalAuditLog::PAGE_SIZE - 1]['target_id']);
        self::assertSame(70, $second[0]['target_id']);
        self::assertCount(20, $third);
        self::assertSame('Mod', $first[0]['actor']);
    }

    public function testFiltersAndMalformedValues(): void
    {
        $log = new DbalAuditLog($this->connection);

        self::assertCount(40, $log->page(['family' => 'thread']));
        $actions = array_column($log->page(['family' => 'thread']), 'action');
        self::assertSame(['thread.move'], array_values(array_unique($actions)));
        self::assertCount(DbalAuditLog::PAGE_SIZE + 1, $log->page(['actor' => 'mod']), 'names compare case-folded');
        self::assertSame([], $log->page(['actor' => 'nobody']));
        self::assertCount(1, $log->page(['target_type' => 'post', 'target_id' => 3]));
        self::assertSame([], $log->page(['target_type' => 'post', 'target_id' => 4]), 'post 4 does not exist');
        self::assertCount(DbalAuditLog::PAGE_SIZE + 1, $log->page(['family' => "post' OR 1=1 --"]), 'ignored');
    }

    public function testPageEscapesAndLinksOlder(): void
    {
        $handler = new AuditLogHandler(
            TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en'),
            TwigFactory::create(
                dirname(__DIR__, 2),
                sys_get_temp_dir() . '/nolviko-no-storage',
                TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en'),
            ),
            new DbalAuditLog($this->connection),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
        );
        $page = $handler->handle(Request::create('/admin/audit'), []);
        $content = (string) $page->getContent();

        self::assertSame(Response::HTTP_OK, $page->getStatusCode());
        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
        self::assertStringContainsString('2026-11-02 10:02:00 UTC', $content);
        self::assertStringContainsString('before=', $content, 'an older link');

        $filtered = (string) $handler->handle(Request::create('/admin/audit?family=ban&actor=x&before=abc'), [])
            ->getContent();
        self::assertStringContainsString('No entries match.', $filtered);
    }

    /**
     * Benchmark case `audit_log_page`: one page of 50 with the actor joined, unfiltered and by actor.
     */
    public function testQueryCountAndLatency(): void
    {
        $counter = new QueryCounter();
        $config = new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        );
        $log = new DbalAuditLog(ConnectionFactory::create($config, [$counter]));
        $log->page([]);
        // The first call also opens the connection; count from a warm one.
        $counter->count = 0;
        $log->page([]);
        $plain = $counter->count;
        $counter->count = 0;
        $log->page(['actor' => 'Mod']);
        $byActor = $counter->count;
        $result = BenchmarkHarness::measure(static fn(): array => $log->page([]), 50, 5);

        fwrite(STDERR, sprintf(
            "audit_log_page p50 %.4f ms  p95 %.4f ms  queries %d (actor filter %d)\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $plain,
            $byActor,
        ));
        self::assertSame(1, $plain);
        self::assertSame(2, $byActor);
    }
}
