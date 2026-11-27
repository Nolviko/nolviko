<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\UpdateJournal;
use Nolviko\Core\Updates\UpdateStage;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Durable update checkpoints: restart, single active job, lease takeover, stale requests and explicit recovery.
 */
final class UpdateJournalTest extends TestCase
{
    private Connection $connection;

    private UpdateJournal $journal;

    private DateTimeImmutable $now;

    private QueryCounter $counter;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->counter = new QueryCounter();
        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ), [$this->counter]);

        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->journal = new UpdateJournal($this->connection);
        $this->now = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testCheckpointsSurviveARequestRestartAndCompleteInOrder(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $run = $this->journal->checkpoint($id, $token, $run->revision, 3, $this->now);
        $this->journal->release($id, $token, $run->revision, $this->now);
        $restarted = new UpdateJournal($this->connection);
        self::assertSame(3, $restarted->find($id)?->cursor);
        $token = $restarted->claim($id, $this->now);
        $run = $restarted->find($id);
        self::assertNotNull($run);

        foreach (array_slice(UpdateStage::cases(), 1) as $stage) {
            $run = $restarted->advance($id, $token, $run->revision, $stage, $this->now);
            self::assertSame($stage, $run->stage);
            self::assertSame(0, $run->cursor);
        }

        self::assertSame(UpdateStage::Completed, $this->journal->find($id)?->stage);
        self::assertNotSame($id, $this->journal->begin('release-3', str_repeat('b', 64), $this->now));
        self::assertStringNotContainsString($token, json_encode($run, JSON_THROW_ON_ERROR));
    }

    public function testOnlyOneActiveRunExists(): void
    {
        $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_already_active');
        $this->journal->begin('release-3', str_repeat('b', 64), $this->now);
    }

    public function testCompetingClaimIsRefusedUntilExpiry(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $old = $this->journal->claim($id, $this->now, 5);
        $run = $this->journal->find($id);
        self::assertNotNull($run);

        try {
            $this->journal->claim($id, $this->now);
            self::fail('two holders acquired one run');
        } catch (RuntimeException $error) {
            self::assertSame('update_lease_held', $error->getMessage(), 'a live lease is "try again later"');
        }

        $later = $this->now->modify('+5 seconds');
        $fresh = $this->journal->claim($id, $later);
        self::assertNotSame($old, $fresh);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_lease_or_revision_lost');
        $this->journal->checkpoint($id, $old, $run->revision, 1, $later);
    }

    public function testExpiredOwnerCannotWriteWithoutATakeover(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now, 5);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_lease_or_revision_lost');
        $this->journal->renew($id, $token, $run->revision, $this->now->modify('+5 seconds'));
    }

    public function testFailureKeepsTheFrontierAndNeedsExplicitRecovery(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $run = $this->journal->checkpoint($id, $token, $run->revision, 4, $this->now);
        $run = $this->journal->fail($id, $token, $run->revision, 'manual_attention', 'update_disk_full', $this->now);
        self::assertSame(4, $run->cursor);
        self::assertSame('update_disk_full', $run->diagnostic);

        try {
            $this->journal->claim($id, $this->now);
            self::fail('failed work resumed without an explicit action');
        } catch (RuntimeException $error) {
            self::assertSame('update_claim_refused', $error->getMessage());
        }

        $this->journal->claim($id, $this->now, recover: true);
        self::assertSame('running', $this->journal->find($id)?->condition);
        self::assertSame(4, $this->journal->find($id)?->cursor);
    }

    public function testStaleCheckpointCannotOverwriteNewerProgress(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $this->journal->checkpoint($id, $token, $run->revision, 1, $this->now);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_lease_or_revision_lost');
        $this->journal->checkpoint($id, $token, $run->revision, 2, $this->now);
    }

    public function testVerificationAndOtherStagesCannotBeSkipped(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_transition_invalid');
        $this->journal->advance($id, $token, $run->revision, UpdateStage::Staged, $this->now);
    }

    public function testAbandonBeforeMaintenancePreservesHistoryAndAllowsANewRun(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $run = $this->journal->abandon($id, $token, $run->revision, $this->now);
        self::assertSame('abandoned', $run->condition);
        self::assertSame(UpdateStage::Discovered, $run->stage);
        self::assertNotSame($id, $this->journal->begin('release-3', str_repeat('b', 64), $this->now));
    }

    public function testAbandonAfterMaintenanceCannotSilentlyReopenAnIncompleteUpdate(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);

        foreach (array_slice(UpdateStage::cases(), 1, 6) as $stage) {
            $run = $this->journal->advance($id, $token, $run->revision, $stage, $this->now);
        }

        self::assertSame(UpdateStage::Maintenance, $run->stage);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_abandon_unsafe');
        $this->journal->abandon($id, $token, $run->revision, $this->now);
    }

    public function testParallelStartsHaveExactlyOneWinner(): void
    {
        foreach (range(1, 4) as $round) {
            $this->race('begin', '');
            $id = $this->connection->fetchOne('SELECT id FROM update_runs WHERE active_slot = 1');
            $token = $this->journal->claim($id, $this->now);
            $run = $this->journal->find($id);
            $this->journal->abandon($id, $token, $run->revision, $this->now);
        }
    }

    public function testBeginningInsideATransactionIsRejectedWithoutRollingItBack(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
            self::fail('Expected a top-level operation');
        } catch (RuntimeException $failure) {
            self::assertSame('update_begin_transaction_active', $failure->getMessage());
            self::assertTrue($this->connection->isTransactionActive());
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM update_runs'));
    }

    public function testParallelClaimsHaveExactlyOneWinner(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $this->race('claim', $id);
    }

    public function testCheckpointQueryBudgetAndTiming(): void
    {
        $id = $this->journal->begin('release-2', str_repeat('a', 64), $this->now);
        $token = $this->journal->claim($id, $this->now);
        $run = $this->journal->find($id);
        self::assertNotNull($run);
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($id, $token, &$run, &$queries): void {
            $this->counter->count = 0;
            $run = $this->journal->checkpoint($id, $token, $run->revision, $run->cursor + 1, $this->now);
            $queries[] = $this->counter->count;
        }, 20, 2);
        self::assertSame(3, max($queries));
        fwrite(STDERR, sprintf(
            "update_checkpoint p50 %.4f ms p95 %.4f ms queries %d peak %d MiB\n",
            $result['p50_ms'],
            $result['p95_ms'],
            max($queries),
            $result['max_peak_bytes'] / 1048576,
        ));
    }
    private function race(string $action, string $id): void
    {
        $directory = new TemporaryDirectory();
        $go = $directory->path . '/go';
        $workers = [];

        foreach (range(1, 4) as $index) {
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/update-worker.php',
                $go,
                $action,
                $id,
                $this->now->format('c'),
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $workers[] = [$process, $pipes];
        }

        touch($go);
        $results = [];

        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }

        self::assertSame(1, count(array_keys($results, 'accepted', true)));
        self::assertSame(3, count(array_keys($results, 'refused', true)));
        $directory->remove();
    }
}
