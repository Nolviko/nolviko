<?php

declare(strict_types=1);

namespace Nolviko\Tests\Portability;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Events\OutboxSchema;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Search\Infrastructure\DbalPostSearch;
use Nolviko\Modules\Search\Infrastructure\SearchDialect;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Standalone queue/search adapter contract in a dedicated PostgreSQL database, not full-app acceptance. */
final class TaskSearchPostgresTest extends TestCase
{
    private Connection $db;

    private bool $owned = false;

    protected function setUp(): void
    {
        if (getenv('FORUM_TEST_PG_DATABASE') !== 'm08_tasks') {
            self::fail('dedicated PostgreSQL fixture required');
        }

        $this->db = DriverManager::getConnection(['driver' => 'pdo_pgsql',
            'host' => getenv('FORUM_TEST_PG_HOST'), 'dbname' => 'm08_tasks',
            'user' => getenv('FORUM_TEST_PG_USER'), 'password' => getenv('FORUM_TEST_PG_PASSWORD')]);
        $known = $this->db->createSchemaManager()->listTableNames();

        if ($known !== [] && !in_array('zz_m08_postgres_fixture', $known, true)) {
            self::fail('refusing non-fixture database');
        }

        if (
            array_diff($known, ['outbox_events', 'posts', 'threads', 'users', 'fixture_effects',
            'zz_m08_postgres_fixture']) !== []
        ) {
            self::fail('refusing unexpected PostgreSQL tables');
        }

        $this->owned = true;
        $this->reset();
        $this->db->executeStatement('CREATE TABLE zz_m08_postgres_fixture (id INT PRIMARY KEY)');

        foreach (OutboxSchema::postgres() as $sql) {
            $this->db->executeStatement($sql);
        }

        $this->db->executeStatement("SET TIME ZONE 'UTC'");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->owned) {
                $this->reset();
            }

            $this->db->close();
        }
    }

    public function testTransactionalOutboxDedupeLeasesRetriesAndStaleAcknowledgement(): void
    {
        $outbox = new Outbox($this->db);
        $this->db->transactional(function () use ($outbox): void {
            self::assertTrue($outbox->record('fixture.event', 'fixture', 1, ['value' => 1], 'same'));
            self::assertFalse($outbox->record('fixture.event', 'fixture', 1, ['value' => 2], 'same'));
        });
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM outbox_events'));
        $queue = new OutboxQueue($this->db);
        $first = $queue->claim(['fixture.event']);
        self::assertNotNull($first);
        self::assertNull($queue->claim(['fixture.event']));
        $this->db->executeStatement(
            "UPDATE outbox_events SET lease_expires_at = clock_timestamp() - INTERVAL '1 second'"
        );
        $second = $queue->claim(['fixture.event']);
        self::assertNotNull($second);
        self::assertNotSame($first->token, $second->token);

        try {
            $this->db->transactional(fn() => $queue->complete($first));
            self::fail('stale acknowledgement accepted');
        } catch (RuntimeException) {
            self::assertSame('running', $this->db->fetchOne('SELECT status FROM outbox_events'));
        }

        $queue->retry($second);
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM outbox_events'));
        $this->db->executeStatement("UPDATE outbox_events SET available_at = clock_timestamp() - INTERVAL '1 second'");
        $last = $queue->claim(['fixture.event']);
        $this->db->transactional(fn() => $queue->complete($last));
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM outbox_events'));
    }

    public function testRunnerRollsBackFailedEffectsAndResumesBoundedProgress(): void
    {
        $this->db->executeStatement('CREATE TABLE fixture_effects (id INT PRIMARY KEY)');
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record('fixture.event', 'fixture', 1, []));
        $runner = new CooperativeTaskRunner($this->db, new OutboxQueue($this->db), [
            'fixture.event' => function (OutboxLease $lease, Closure $guard): bool {
                $guard();
                $this->db->insert('fixture_effects', ['id' => 1]);
                throw new RuntimeException('expected_failure');
            },
        ], static fn(): bool => true);
        self::assertSame(1, $runner->run(1, 2000)['failed']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM fixture_effects'));
        $this->db->executeStatement("UPDATE outbox_events SET available_at = clock_timestamp() - INTERVAL '1 second'");
        $runner = new CooperativeTaskRunner($this->db, new OutboxQueue($this->db), [
            'fixture.event' => static fn(OutboxLease $lease, Closure $guard): bool => false,
        ], static fn(): bool => true);
        self::assertSame(1, $runner->run(1, 2000)['progress']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT attempts FROM outbox_events'));
    }

    public function testNativeSearchFiltersVisibilityBeforeCountAndPage(): void
    {
        $this->db->executeStatement('CREATE TABLE users (id BIGINT PRIMARY KEY, username VARCHAR(64))');
        $this->db->executeStatement('CREATE TABLE threads (id BIGINT PRIMARY KEY, forum_id BIGINT NOT NULL, '
            . 'title VARCHAR(160), status VARCHAR(16), moved_to_thread_id BIGINT, deleted_at TIMESTAMP)');
        $this->db->executeStatement('CREATE TABLE posts (id BIGINT PRIMARY KEY, thread_id BIGINT NOT NULL, '
            . 'user_id BIGINT, content TEXT NOT NULL, status VARCHAR(16), deleted_at TIMESTAMP, '
            . 'created_at TIMESTAMP NOT NULL)');

        foreach (SearchDialect::indexStatements('postgresql') as $sql) {
            $this->db->executeStatement($sql);
        }

        $this->db->insert('users', ['id' => 1, 'username' => 'author']);

        foreach ([1 => 1, 2 => 2] as $id => $forum) {
            $this->db->insert(
                'threads',
                ['id' => $id, 'forum_id' => $forum, 'title' => 'fixture', 'status' => 'visible']
            );
        }

        foreach ([1 => 1, 2 => 1, 3 => 2] as $id => $thread) {
            $this->db->insert('posts', ['id' => $id, 'thread_id' => $thread, 'user_id' => 1,
                'content' => 'acornquery İSTANBUL <script>alert(1)</script>', 'status' => 'visible',
                'created_at' => $id === 2 ? '2026-11-02 12:00:00' : '2026-11-01 12:00:00']);
        }

        $search = new DbalPostSearch($this->db);
        $scope = new ForumScope([1]);
        $result = $search->find($scope, SearchQuery::parse('acornquery'));
        self::assertSame(2, $result['count']);
        self::assertSame([2, 1], array_map(intval(...), array_column($result['rows'], 'id')));
        self::assertIsString($result['rows'][0]['excerpt']);
        self::assertSame(0, $search->find(
            $scope,
            SearchQuery::parse('acornquery'),
            filters: new SearchFilters(forum: 2)
        )['count']);
        self::assertSame(1, $search->find(
            $scope,
            SearchQuery::parse('acornquery'),
            filters: new SearchFilters(author: 1, from: '2026-11-02', until: '2026-11-02')
        )['count']);
        self::assertSame(2, $search->find($scope, SearchQuery::parse('istanbul'))['count']);
        $this->db->executeStatement('SET enable_seqscan = off');
        $plan = $this->db->fetchFirstColumn('EXPLAIN SELECT id FROM posts p WHERE '
            . SearchDialect::predicate(true), ['acornquery:*']);
        self::assertStringContainsString('ft_posts_content', implode("\n", $plan));
    }

    private function reset(): void
    {
        $tables = ['fixture_effects', 'posts', 'threads', 'users', 'outbox_events', 'zz_m08_postgres_fixture'];

        foreach ($tables as $table) {
            $this->db->executeStatement('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
        }
    }
}
