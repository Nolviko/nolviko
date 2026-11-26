<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\Outbox;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * M08.01 (minimal part) on MySQL 8.4: an event is recorded only inside a transaction, commits and rolls back
 * with it, and a repeated dedupe key keeps one row without aborting the transaction.
 */
final class OutboxTest extends TestCase
{
    private Connection $connection;

    private Outbox $outbox;

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
        $this->outbox = new Outbox($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testDueProbeIncludesExpiredLeasesAndExcludesFutureWork(): void
    {
        $queue = new OutboxQueue($this->connection);
        self::assertFalse($queue->hasDue(['post.created']));
        $this->connection->transactional(fn(): bool => $this->outbox->record('post.created', 'post', 1, []));
        self::assertTrue($queue->hasDue(['post.created']));
        self::assertFalse($queue->hasDue(['thread.created']));
        self::assertFalse($queue->hasDue([]));

        $this->connection->executeStatement(
            "UPDATE outbox_events SET available_at = '2099-01-01 00:00:00'",
        );
        self::assertFalse($queue->hasDue(['post.created']));

        $this->connection->executeStatement(
            "UPDATE outbox_events SET status = 'running', lease_expires_at = '2099-01-01 00:00:00'",
        );
        self::assertFalse($queue->hasDue(['post.created']));

        $this->connection->executeStatement("UPDATE outbox_events SET lease_expires_at = '2000-01-01 00:00:00'");
        self::assertTrue($queue->hasDue(['post.created']));
        self::assertFalse($queue->hasDue(['thread.created']));
    }

    public function testLocalHandlerRollsBackItsWorkAndRecordsARetryBeforeCompleting(): void
    {
        $this->connection->transactional(fn(): bool => $this->outbox->record('post.created', 'post', 1, []));
        $fail = true;
        $runner = new CooperativeTaskRunner(
            $this->connection,
            new OutboxQueue($this->connection),
            ['post.created' => function () use (&$fail): bool {
                $this->outbox->record('fixture.notice', 'post', 1, []);
                if ($fail) {
                    throw new RuntimeException('fixture_notice_failed');
                }

                return true;
            }],
            static fn(): bool => true,
            ['post.created'],
            localBulk: true,
        );

        self::assertSame(['completed' => 0, 'progress' => 0, 'failed' => 1], $runner->run(1));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM outbox_events'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT attempts FROM outbox_events'));

        $fail = false;
        $this->connection->executeStatement("UPDATE outbox_events SET available_at = '2000-01-01 00:00:00'");
        self::assertSame(['completed' => 1, 'progress' => 0, 'failed' => 0], $runner->run(1));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
        self::assertSame('completed', $this->connection->fetchOne(
            "SELECT status FROM outbox_events WHERE event_type = 'post.created'",
        ));
    }

    public function testCommittedEventIsPendingWithItsPayload(): void
    {
        $recorded = $this->connection->transactional(
            fn(): bool => $this->outbox->record('thread.created', 'thread', 7, ['forum_id' => 3, 'title' => 'Ü']),
        );
        $row = $this->connection->fetchAssociative('SELECT * FROM outbox_events');

        self::assertTrue($recorded);
        self::assertIsArray($row);
        self::assertSame('thread.created', $row['event_type']);
        self::assertSame('thread', $row['aggregate_type']);
        self::assertSame(7, (int) $row['aggregate_id']);
        self::assertSame('thread.created:7', $row['dedupe_key']);
        self::assertSame(Outbox::PENDING, $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame(['forum_id' => 3, 'title' => 'Ü'], json_decode((string) $row['payload'], true));
    }

    public function testRollbackRemovesTheEvent(): void
    {
        try {
            $this->connection->transactional(function (): void {
                $this->outbox->record('thread.created', 'thread', 1, []);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testDuplicateDedupeKeyIsANoOpAndTheTransactionStillCommits(): void
    {
        $results = $this->connection->transactional(fn(): array => [
            $this->outbox->record('post.created', 'post', 5, [], 'form:abc'),
            $this->outbox->record('post.created', 'post', 6, [], 'form:abc'),
            $this->outbox->record('post.created', 'post', 6, []),
        ]);

        self::assertSame([true, false, true], $results);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_events'));
    }

    public function testRecordingOutsideATransactionOrWithABadTypeIsRefused(): void
    {
        try {
            $this->outbox->record('thread.created', 'thread', 1, []);
            self::fail('recorded outside a transaction');
        } catch (LogicException $exception) {
            self::assertSame('outbox_requires_transaction', $exception->getMessage());
        }

        $this->expectExceptionMessage('outbox_event_type_invalid');
        $this->connection->transactional(fn(): bool => $this->outbox->record('Created', 'thread', 1, []));
    }
}
