<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\DatabaseBackups;
use Nolviko\Core\Database\UpdateBackup;
use Nolviko\Core\Database\UpdateFileSwitch;
use Nolviko\Core\Database\UpdateJournal;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\ResumableFileSwap;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateStage;
use Nolviko\Tests\Support\FileSwitchFixture;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileSwitchTest extends TestCase
{
    private Connection $db;

    private FileSwitchFixture $fixture;

    private UpdateJournal $journal;

    private BackupChunkStore $backups;

    private string $id;

    private string $token;

    private UpdateRun $run;

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
            new Secret(str_repeat('s', 32)),
        ));

        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('Refusing an installed database');
        }

        TestDatabase::reset($this->db);
        $this->fixture = new FileSwitchFixture();
        mkdir($this->fixture->private . '/backups', 0700);
        $this->backups = new BackupChunkStore($this->fixture->private . '/backups', $this->fixture->live);
        $this->journal = new UpdateJournal($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->root->remove();
        }

        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testNewRequestsResumeAndOnlyThenRecordFilesSwitched(): void
    {
        $this->prepare();
        $steps = 0;

        do {
            $this->run = $this->coordinator()->step(
                $this->fixture->plan(),
                $this->id,
                $this->token,
                $this->run->revision,
                1,
            );
            ++$steps;

            if ($this->run->stage === UpdateStage::Maintenance) {
                self::assertSame($steps, $this->run->cursor);
                $this->journal->release($this->id, $this->token, $this->run->revision, new DateTimeImmutable('now'));
                $this->journal = new UpdateJournal($this->db);
                $this->token = $this->journal->claim($this->id, new DateTimeImmutable('now'));
                $this->run = $this->journal->find($this->id);
            }
        } while ($this->run->stage === UpdateStage::Maintenance);

        self::assertSame(3, $steps);
        self::assertSame(UpdateStage::FilesSwitched, $this->run->stage);
        self::assertSame(0, $this->run->cursor);
        self::assertSame('new-vendor', file_get_contents($this->fixture->live . '/vendor/autoload.php'));
        self::assertFileDoesNotExist($this->fixture->live . '/public/obsolete.php');
        self::assertSame('private-config', file_get_contents($this->fixture->live . '/config/local.php'));
        self::assertFileExists($this->fixture->live . '/config/maintenance.lock');
    }

    public function testExpiredOwnerCannotMutateFiles(): void
    {
        $this->prepare();
        $this->db->executeStatement(
            'UPDATE update_runs SET lease_expires_at = UTC_TIMESTAMP(6) WHERE id = ?',
            [$this->id],
        );
        $this->expectExceptionMessage('update_switch_lease_lost');
        $this->coordinator()->step($this->fixture->plan(), $this->id, $this->token, $this->run->revision);
    }

    public function testCorruptBackupStopsBeforeTheFirstMutation(): void
    {
        $this->prepare();
        $handle = $this->journal->backup($this->id);
        file_put_contents($this->fixture->private . '/backups/' . $handle['id'] . '.chunk', 'corrupt');

        try {
            $this->coordinator()->step($this->fixture->plan(), $this->id, $this->token, $this->run->revision);
            self::fail('Expected backup verification failure');
        } catch (RuntimeException $failure) {
            self::assertSame('backup_chunk_integrity_failed', $failure->getMessage());
        }

        self::assertSame('old-a', file_get_contents($this->fixture->live . '/public/a.php'));
        self::assertSame(0, $this->journal->find($this->id)->cursor);
    }

    public function testAnUnbackedOwnedFilePreventsActivation(): void
    {
        $this->prepare(true);
        $this->expectExceptionMessage('update_backup_file_missing_or_modified');
        $this->coordinator()->step($this->fixture->plan(), $this->id, $this->token, $this->run->revision);
    }

    public function testFilesystemCheckpointAheadOfDatabaseIsReconciledByReplay(): void
    {
        $this->prepare();
        $plan = $this->fixture->plan();
        (new ResumableFileSwap($this->fixture->live, $this->fixture->private))->step(
            $plan,
            $this->id,
            function () use ($plan): void {
                $this->journal->assertFileSwitchLease(
                    $this->id,
                    $this->token,
                    $this->run->revision,
                    $plan->manifestHash,
                );
            },
            1,
        );
        self::assertSame(0, $this->journal->find($this->id)->cursor);
        $this->run = $this->coordinator()->step($plan, $this->id, $this->token, $this->run->revision, 1);
        self::assertSame(2, $this->run->cursor);
        $this->run = $this->coordinator()->step($plan, $this->id, $this->token, $this->run->revision, 1);
        self::assertSame(UpdateStage::FilesSwitched, $this->run->stage);
    }

    public function testStaleRevisionCannotAcknowledgeFilesystemWork(): void
    {
        $this->prepare();
        $oldRevision = $this->run->revision;
        $plan = $this->fixture->plan();
        $this->coordinator()->step($plan, $this->id, $this->token, $oldRevision, 1);
        $this->expectExceptionMessage('update_switch_lease_lost');
        $this->journal->recordFileSwitch($this->id, $this->token, $oldRevision, $plan->manifestHash, 2, false);
    }

    public function testAnotherManifestCannotReuseTheLease(): void
    {
        $this->prepare();
        $other = new FileSwitchFixture([], ['public/a.php' => 'other-release']);

        try {
            $this->expectExceptionMessage('update_switch_lease_lost');
            $this->coordinator()->step($other->plan(), $this->id, $this->token, $this->run->revision);
        } finally {
            $other->root->remove();
        }
    }

    private function prepare(bool $omitOwnedFile = false): void
    {
        $now = new DateTimeImmutable('now');
        $this->id = $this->journal->begin('switch-test', $this->fixture->manifest->manifestHash, $now);
        $this->token = $this->journal->claim($this->id, $now);
        $this->run = $this->journal->find($this->id);
        $stages = [UpdateStage::Checked, UpdateStage::Downloaded, UpdateStage::Verified, UpdateStage::Staged];

        foreach ($stages as $stage) {
            $this->run = $this->journal->advance($this->id, $this->token, $this->run->revision, $stage, $now);
        }

        $paths = ['public/a.php', 'config/local.php', 'config/install.lock', 'storage/uploads/member.bin'];

        if (!$omitOwnedFile) {
            $paths[] = 'public/obsolete.php';
        }

        $this->run = (new UpdateBackup(DatabaseBackups::for($this->db), $this->journal))->export(
            $this->id,
            $this->token,
            $this->run->revision,
            $this->backups,
            $this->fixture->live,
            $paths,
        );
        $this->run = $this->journal->advance(
            $this->id,
            $this->token,
            $this->run->revision,
            UpdateStage::Maintenance,
            new DateTimeImmutable('now'),
        );
    }

    private function coordinator(): UpdateFileSwitch
    {
        return new UpdateFileSwitch(
            $this->journal,
            $this->backups,
            new ResumableFileSwap($this->fixture->live, $this->fixture->private),
        );
    }
}
