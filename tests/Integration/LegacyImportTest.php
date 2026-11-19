<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Modules\Admin\Infrastructure\SystemDiagnostics;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportRecords;
use Nolviko\Modules\Importers\Infrastructure\ImportAdministration;
use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\ImportRunner;
use Nolviko\Modules\Importers\Infrastructure\ImportSources;
use Nolviko\Modules\Importers\Infrastructure\ImportTarget;
use Nolviko\Modules\Importers\Infrastructure\InlineAttachments;
use Nolviko\Modules\Importers\Infrastructure\LegacyRedirects;
use Nolviko\Modules\Importers\Infrastructure\PrivateImportFiles;
use Nolviko\Modules\Importers\Infrastructure\SqlImportSource;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\LegacyPassword;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\LegacyForumFixture;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyImportTest extends TestCase
{
    private Connection $db;
    private Connection $sourceDb;
    private QueryCounter $sourceCounter;
    private TemporaryDirectory $files;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }
        $config = new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        );
        $this->db = ConnectionFactory::create($config);
        $this->sourceCounter = new QueryCounter();
        $sourceConfig = $config;

        // Legacy forums live on MySQL: a PostgreSQL forum imports from the MySQL test service (2026-11-14).
        if (TestDatabase::driver() === 'postgresql') {
            if ((getenv('FORUM_TEST_SOURCE_DB_HOST') ?: '') === '') {
                self::markTestSkipped('FORUM_TEST_SOURCE_DB_HOST (a MySQL source) not set');
            }

            $sourceConfig = new Config(
                'mysql',
                (string) getenv('FORUM_TEST_SOURCE_DB_HOST'),
                null,
                (string) getenv('FORUM_TEST_DB_NAME'),
                (string) getenv('FORUM_TEST_DB_USER'),
                new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
                new Secret(str_repeat('s', 32))
            );
        }

        $this->sourceDb = ConnectionFactory::create($sourceConfig, [$this->sourceCounter]);
        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('refusing installed forum');
        }
        TestDatabase::reset($this->db);
        $this->files = new TemporaryDirectory();
        $this->files->write('storage/.keep', '');
        $this->files->write('public/.keep', '');
        $this->db->insert('users', ['id' => 1, 'username' => 'Owner', 'username_normalized' => 'owner',
            'email' => 'owner@example.test', 'email_normalized' => 'owner@example.test', 'password_hash'
                => 'untouched',
            'status' => 'active', 'created_at' => '2020-01-01 00:00:00.000000',
            'updated_at' => '2020-01-01 00:00:00.000000']);

        if (TestDatabase::driver() === 'postgresql') {
            // MySQL moves AUTO_INCREMENT past an explicit id by itself.
            $this->db->executeStatement("SELECT setval(pg_get_serial_sequence('users', 'id'), 1)");
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if (isset($this->sourceDb)) {
                $this->sourceDb->close();
            }
            TestDatabase::release($this->db);
            if (isset($this->files)) {
                $this->files->remove();
            }
        }
    }

    public static function engines(): array
    {
        return [['smf'], ['mybb'], ['phpbb']];
    }

    #[DataProvider('engines')]
    public function testRealSourceRoundTripResumePrivacyCountsAndNoRoleEscalation(string $engine): void
    {
        LegacyForumFixture::install($this->sourceDb, $engine);
        $source = new SqlImportSource($this->sourceDb, $engine, 'legacy_', 'fixture-' . $engine);
        $files = new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public');
        $runner = new ImportRunner($this->db, $source, $files);
        $job = $runner->begin(1, true);
        self::assertSame($job, $runner->begin(1, true));
        $status = $runner->read($job);
        for ($iteration = 0; !$status['complete'] && $iteration < 150; ++$iteration) {
            $runner = new ImportRunner($this->db, $source, $files);
            $status = $runner->step($job, $status['revision'], 1);
        }
        self::assertTrue($status['complete']);
        self::assertSame($status['processed'], $status['verified']);
        self::assertSame($status, $runner->step($job, $status['revision']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM posts'));
        self::assertSame('[b]Çığ[/b]', $this->db->fetchOne('SELECT content FROM posts'));
        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT post_count FROM forum_statistics WHERE post_count > 0')
        );
        self::assertSame(3, (int) $this->db->fetchOne('SELECT imported_votes + '
            . '(SELECT COUNT(*) FROM poll_votes) FROM poll_options LIMIT 1'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM outbox_events'));
        $user = $this->db->fetchAssociative(
            'SELECT id, status, password_hash FROM users WHERE username = ?',
            ['Migrated']
        );
        self::assertSame('pending_approval', $user['status']);
        self::assertTrue(LegacyPassword::verify('fixture-password', $user['password_hash']));
        self::assertSame('untouched', $this->db->fetchOne('SELECT password_hash FROM users WHERE id = 1'));
        self::assertSame(
            0,
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM usergroup_members WHERE user_id = ? '
                . 'AND usergroup_id IN (1, 3)',
                [(int) $user['id']]
            )
        );
        self::assertSame(
            '[i]İmza[/i]',
            $this->db->fetchOne(
                'SELECT imported_signature FROM member_profiles '
                . 'WHERE user_id = ?',
                [(int) $user['id']]
            )
        );
        self::assertSame('private', $this->db->fetchOne(
            'SELECT visibility FROM member_profiles WHERE user_id = ?',
            [(int) $user['id']]
        ));
        self::assertSame(0, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM forum_permissions WHERE value <> ?',
            ['never']
        ));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT MAX(n) FROM (SELECT COUNT(*) n FROM '
            . 'private_conversation_members GROUP BY conversation_id) counts'));
        $catalog = PermissionCatalog::core(true);
        $resolver = new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        );
        $visibility = new ForumVisibility(
            new FileCache($this->files->path . '/cache', $this->files->path),
            fn(): int => (new VersionCounter($this->db))->current(VersionCounter::PERMISSION_RULES)
        );
        $redirects = new LegacyRedirects(
            $this->db,
            $visibility,
            RouterFactory::create(dirname(__DIR__, 2), $this->files->path . '/storage')
        );
        self::assertNull($redirects->target($job, 'posts', 1, $resolver->guest()));
        self::assertNull($redirects->target($job, 'users', 2, $resolver->guest()));
        $this->db->executeStatement('DELETE FROM forum_permissions');
        $fresh = new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        );
        $this->db->transactional(function (): void {
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
        });
        $currentVisibility = new ForumVisibility(
            new FileCache($this->files->path . '/cache', $this->files->path),
            fn(): int => (new VersionCounter($this->db))->current(VersionCounter::PERMISSION_RULES)
        );
        $redirects = new LegacyRedirects(
            $this->db,
            $currentVisibility,
            RouterFactory::create(dirname(__DIR__, 2), $this->files->path . '/storage')
        );
        self::assertSame('/posts/1', $redirects->target($job, 'posts', 1, $fresh->guest()));
        $this->db->update('posts', ['deleted_at' => '2026-01-01 00:00:00.000000'], ['id' => 1]);
        self::assertNull($redirects->target($job, 'posts', 1, $fresh->guest()));

        $bundle = (new SystemDiagnostics(
            $this->db,
            $this->files->path,
            $this->files->path . '/storage'
        ))->bundle();
        self::assertStringNotContainsString('migrated@example.test', $bundle);
        self::assertStringNotContainsString('Private body', $bundle);
        self::assertStringNotContainsString('fixture-password', $bundle);
        self::assertLessThan(65536, strlen($bundle));
    }

    #[DataProvider('engines')]
    public function testStaleRevisionRollsBackAndSourceChangeIsDetected(string $engine): void
    {
        LegacyForumFixture::install($this->sourceDb, $engine);
        $source = new SqlImportSource($this->sourceDb, $engine, 'legacy_', 'fixture-' . $engine);
        $runner = new ImportRunner(
            $this->db,
            $source,
            new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public')
        );
        $job = $runner->begin(1, true);
        $status = $runner->step($job, 0, 1);
        try {
            $runner->step($job, 0, 1);
            self::fail('stale checkpoint accepted');
        } catch (RuntimeException $failure) {
            self::assertSame('import_checkpoint_conflict', $failure->getMessage());
            self::assertSame($status, $runner->read($job));
        }
        while (
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM import_map WHERE entity = ?',
                ['posts']
            ) === 0
        ) {
            $status = $runner->step($job, $status['revision'], 1);
        }
        $this->db->executeStatement("UPDATE posts SET content = 'changed after copy'");
        $this->expectExceptionMessage('import_parity_mismatch');
        for ($iteration = 0; $iteration < 150; ++$iteration) {
            $status = $runner->step($job, $status['revision'], 1);
        }
    }

    #[DataProvider('engines')]
    public function testOriginalFilesAndPrivateOwnershipSurviveImport(string $engine): void
    {
        LegacyForumFixture::install($this->sourceDb, $engine);
        $bytes = "\0\xfforiginal attachment<?php echo 'must never execute'; ?>";
        $stored = match ($engine) {
            'smf' => '1_' . str_repeat('a', 40) . '.dat',
            'mybb' => '202601/post_2_1700000000_' . str_repeat('a', 32) . '.attach',
            'phpbb' => '2_' . str_repeat('a', 32),
        };
        $this->files->write('source/' . $stored, $bytes);
        $attachment = match ($engine) {
            'smf' => ['id_attach' => 1, 'id_msg' => 1, 'id_member' => 2, 'filename' => 'ç-file.bin',
                'file_hash' => str_repeat('a', 40), 'id_folder' => 1, 'size' => strlen($bytes),
                    'approved' => 1],
            'mybb' => ['aid' => 1, 'pid' => 1, 'uid' => 2, 'filename' => 'ç-file.bin',
                'attachname' => $stored, 'filesize' => strlen($bytes), 'visible' => 1, 'dateuploaded'
                    => 1700000000],
            'phpbb' => ['attach_id' => 1, 'post_msg_id' => 1, 'poster_id' => 2, 'real_filename'
                => 'ç-file.bin',
                'physical_filename' => $stored, 'filesize' => strlen($bytes), 'filetime' => 1700000000],
        };
        $this->sourceDb->insert('legacy_attachments', $attachment);
        if ($engine === 'phpbb') {
            $attachment['attach_id'] = 2;
            $attachment['in_message'] = 1;
            $this->sourceDb->insert('legacy_attachments', $attachment);
        }
        $source = new SqlImportSource(
            $this->sourceDb,
            $engine,
            'legacy_',
            'fixture-' . $engine,
            'UTF-8',
            ['1' => $this->files->path . '/source']
        );
        $store = new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public');
        $runner = new ImportRunner($this->db, $source, $store);
        $job = $runner->begin(1, true);
        $status = $runner->read($job);
        for ($iteration = 0; !$status['complete'] && $iteration < 100; ++$iteration) {
            $status = $runner->step($job, $status['revision']);
        }
        self::assertTrue($status['complete']);
        $key = $this->db->fetchOne('SELECT file_key FROM community_media LIMIT 1');
        self::assertSame($bytes, file_get_contents($store->path($key)));
        self::assertSame(
            'application/octet-stream',
            $this->db->fetchOne('SELECT mime FROM community_media LIMIT 1')
        );
        if ($engine === 'phpbb') {
            $file = $this->db->fetchAssociative('SELECT id, owner_id FROM import_private_files LIMIT 1');
            $private = new PrivateImportFiles($this->db, $store);
            $response = $private->download((int) $file['owner_id'], $file['id']);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertFalse($response->headers->has('ETag'));
            self::assertSame($bytes, file_get_contents($response->getFile()->getPathname()));
            $this->expectException(RegistrationRejected::class);
            $private->download(1, $file['id']);
        }
    }

    public function testExplicitOwnerLinkKeepsNativeDefaultProfileVisibilityAndExistingCredentials(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $this->sourceDb->update('legacy_users', ['email' => 'owner@example.test'], ['uid' => 2]);
        $source = new SqlImportSource(
            $this->sourceDb,
            'mybb',
            'legacy_',
            'fixture-mybb',
            'UTF-8',
            [],
            [2 => 1]
        );
        $runner = new ImportRunner(
            $this->db,
            $source,
            new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public')
        );
        $job = $runner->begin(1, true);
        $status = $runner->read($job);
        for ($iteration = 0; !$status['complete'] && $iteration < 100; ++$iteration) {
            $status = $runner->step($job, $status['revision']);
        }
        self::assertTrue($status['complete']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame('Owner', $this->db->fetchOne('SELECT username FROM users WHERE id = 1'));
        self::assertSame('untouched', $this->db->fetchOne('SELECT password_hash FROM users WHERE id = 1'));
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
        $redirects = new LegacyRedirects(
            $this->db,
            $visibility,
            RouterFactory::create(dirname(__DIR__, 2), $this->files->path . '/storage')
        );
        self::assertSame('/community/profile?user=1', $redirects->target(
            $job,
            'users',
            2,
            $permissions->guest()
        ));
    }

    public function testSourceBatchIsBoundedAndHasConstantQueryCost(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        for ($id = 2; $id <= 30; ++$id) {
            $this->sourceDb->insert('legacy_posts', ['pid' => $id, 'tid' => 1, 'uid' => 2, 'message'
                => 'Post ' . $id,
                'dateline' => 1700000000, 'visible' => 1]);
        }
        $source = new SqlImportSource($this->sourceDb, 'mybb', 'legacy_', 'fixture-mybb');
        $operation = function () use ($source): array {
            $this->sourceCounter->count = 0;
            $this->sourceCounter->statements = [];
            return $source->rows('posts', '', 25);
        };
        for ($run = 0; $run < 3; ++$run) {
            $report = BenchmarkHarness::measure($operation, 100, 100);
            $report['sql_statements'] = $this->sourceCounter->count;
            fwrite(STDERR, 'legacy_source_batch25 ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        }
        self::assertCount(25, $operation());
        self::assertSame(2, $this->sourceCounter->count);
        self::assertFalse($this->sourceDb->isTransactionActive());
    }

    public function testCollisionLeavesUsersAndCheckpointUntouched(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $this->sourceDb->update('legacy_users', ['username' => 'Owner'], ['uid' => 2]);
        $source = new SqlImportSource($this->sourceDb, 'mybb', 'legacy_', 'fixture-mybb');
        $runner = new ImportRunner($this->db, $source);
        $job = $runner->begin(1, true);
        $status = $runner->step($job, 0);
        $status = $runner->step($job, $status['revision']);
        try {
            $runner->step($job, $status['revision']);
            self::fail('collision merged accounts');
        } catch (RuntimeException $failure) {
            self::assertSame('import_identity_collision', $failure->getMessage());
            self::assertSame($status, $runner->read($job));
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM users'));
        }
    }

    /**
     * Beta.11: a source added in the panel is checked (forum, prefix, version found automatically), runs to the end
     * in very few requests, reports per-kind counts outside the checkpoint, exports no member data, and stays
     * hidden until the owner publishes it.
     */
    #[DataProvider('engines')]
    public function testPanelSourceRunsReportsAndOpensOnlyWhenPublished(string $engine): void
    {
        // Only this engine's tables, so finding the forum automatically has exactly one answer (legacy_redirects is
        // the forum's own table when source and forum share the test database).
        foreach (
            $this->sourceDb->fetchFirstColumn("SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name LIKE 'legacy!_%' ESCAPE '!' "
            . "AND table_name <> 'legacy_redirects'") as $table
        ) {
            $this->sourceDb->executeStatement('DROP TABLE ' . $this->sourceDb->quoteIdentifier((string) $table));
        }
        LegacyForumFixture::install($this->sourceDb, $engine);
        $admin = $this->administration();
        // "Find automatically": the check names the forum and its prefix by itself.
        $fields = ['engine' => ''] + $this->sourceFields($engine);
        $added = $admin->add($fields, 1);
        self::assertNotNull($added['id'], json_encode($added['preflight'], JSON_THROW_ON_ERROR));
        self::assertSame($engine, $added['preflight']['engine']);
        self::assertSame('legacy_', $added['preflight']['prefix']);
        self::assertIsString($added['preflight']['version']);
        $sealed = (string) $this->db->fetchOne('SELECT password_sealed FROM import_sources');
        if ($fields['password'] !== '') {
            self::assertStringNotContainsString($fields['password'], $sealed);
            $catalogue = json_encode($admin->sources(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($fields['password'], $catalogue);
        }

        $job = $admin->begin((string) $added['id'], 1, true);
        self::assertSame($job, $admin->begin((string) $added['id'], 1, true));
        $revision = (int) $admin->job($job)['revision'];
        $requests = 0;
        do {
            // A generous time share, so a slow test machine cannot split the request (one request runs batches
            // until its time is used up; 5 seconds in production).
            $status = $admin->run($job, $revision, 60.0);
            $revision = $status['revision'];
            ++$requests;
        } while (!$status['complete'] && $requests < 30);
        self::assertTrue($status['complete']);
        self::assertSame(1, $requests, 'one request runs many batches');

        $report = $admin->report($job);
        $posts = ['processed' => 1, 'verified' => 1, 'skipped' => 0, 'review' => 0];
        self::assertSame($posts, $report['counts']['posts']);
        self::assertTrue($report['quarantined']);
        $stored = json_decode((string) $this->db->fetchOne('SELECT report_json FROM import_jobs'), true);
        self::assertArrayNotHasKey('counts', $stored);
        $export = json_encode($admin->export($job), JSON_THROW_ON_ERROR);
        foreach (['migrated@example.test', 'Private body', 'fixture-password', 'legacy_'] as $private) {
            self::assertStringNotContainsString($private, $export);
        }

        $hidden = "SELECT COUNT(*) FROM forum_permissions WHERE permission_key = 'forum.view' AND value = 'never'";
        self::assertGreaterThan(0, (int) $this->db->fetchOne($hidden));
        $published = $admin->publish($job, true);
        self::assertGreaterThan(0, $published['forums']);
        self::assertSame(0, (int) $this->db->fetchOne($hidden));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM users WHERE username = ?', ['Migrated']));
        self::assertTrue($admin->report($job)['published']);
        self::assertSame(['forums' => 0, 'members' => 0], $admin->publish($job, true));
    }

    public function testCheckExplainsWhatIsMissingWithoutSaving(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $admin = $this->administration();
        $missing = $admin->add(['prefix' => 'nothing_here_'] + $this->sourceFields('mybb'), 1);
        self::assertNull($missing['id']);
        self::assertSame(['import_source_not_found'], $missing['preflight']['problems']);
        $folder = $admin->add(['attachment_root' => '/no/such/folder'] + $this->sourceFields('mybb'), 1);
        self::assertNull($folder['id']);
        self::assertContains('import_attachment_dir_missing', $folder['preflight']['problems']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_sources'));
        try {
            $admin->add(['password' => 'wrong-' . bin2hex(random_bytes(4))] + $this->sourceFields('mybb'), 1);
            self::fail('a wrong password was accepted');
        } catch (RuntimeException $failure) {
            self::assertSame('import_source_unavailable', $failure->getMessage());
        }
    }

    /** Coverage reads only the source's own tables: unrelated tables in the same database do not count. */
    public function testUnrelatedTablesInTheSourceDatabaseDoNotBlockTheStart(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $created = [];
        try {
            for ($table = 0; $table < 520; ++$table) {
                $created[] = $name = 'zz_unrelated_' . $table;
                $this->sourceDb->executeStatement('CREATE TABLE IF NOT EXISTS ' . $name . ' (id INT)');
            }
            $source = new SqlImportSource($this->sourceDb, 'mybb', 'legacy_', 'fixture-mybb');
            $job = (new ImportRunner($this->db, $source))->begin(1, true);
            $json = (string) $this->db->fetchOne('SELECT report_json FROM import_jobs WHERE id = ?', [$job]);
            $report = json_decode($json, true);
            foreach ($report['coverage'] as $table) {
                self::assertStringStartsNotWith('zz_', $table['table']);
            }
        } finally {
            foreach ($created as $name) {
                $this->sourceDb->executeStatement('DROP TABLE IF EXISTS ' . $name);
            }
        }
    }

    /** Jobs started before beta.11 kept counters in their report; the next batch moves them out and the job ends. */
    public function testCountersOfAnOlderJobMoveOutOfTheReport(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $source = new SqlImportSource($this->sourceDb, 'mybb', 'legacy_', 'fixture-mybb');
        $files = new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public');
        $runner = new ImportRunner($this->db, $source, $files);
        $job = $runner->begin(1, true);
        $status = $runner->read($job);
        for ($iteration = 0; $iteration < 4; ++$iteration) {
            $status = $runner->step($job, $status['revision'], 1);
        }
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT entity, processed FROM import_counts') as $row) {
            $counts[$row['entity']] = (int) $row['processed'];
        }
        self::assertNotSame([], $counts);
        $this->db->executeStatement('DELETE FROM import_counts');
        $report = json_decode((string) $this->db->fetchOne('SELECT report_json FROM import_jobs'), true);
        $report['counts'] = $counts;
        $report['verified_counts'] = [];
        $this->db->update('import_jobs', ['report_json' => json_encode($report)], ['id' => $job]);

        for ($iteration = 0; !$status['complete'] && $iteration < 150; ++$iteration) {
            $status = $runner->step($job, $status['revision']);
        }
        self::assertTrue($status['complete']);
        self::assertArrayNotHasKey(
            'counts',
            json_decode((string) $this->db->fetchOne('SELECT report_json FROM import_jobs'), true)
        );
        $unverified = 'SELECT COUNT(*) FROM import_counts WHERE processed <> verified';
        self::assertSame(0, (int) $this->db->fetchOne($unverified));
    }

    /**
     * ADR-038 contract 1 (2026-11-20): the core target refuses malformed records, a changed phase list and a stale
     * revision, and leaves the job exactly as it was.
     */
    public function testImportTargetRefusesMalformedBatchesWithoutWriting(): void
    {
        LegacyForumFixture::install($this->sourceDb, 'mybb');
        $source = new SqlImportSource($this->sourceDb, 'mybb', 'legacy_', 'fixture-mybb');
        $target = new ImportTarget($this->db);
        $identity = $source->identity();
        $phases = $source->phases();
        $job = $target->begin($identity, $source->coverage(), $phases, 1, true);
        $next = $target->next($job, $identity, $phases);
        self::assertSame(['phase' => $phases[0], 'cursor' => '', 'revision' => 0, 'complete' => false], $next);
        $key = ImportCursor::key([1]);
        $hash = str_repeat('a', 64);
        $refused = [
            ['import_record_invalid', [['id' => 1, '_key' => $key, '_hash' => $hash, 'name' => ['nested']]]],
            ['import_record_invalid', [['id' => 1, '_key' => $key, '_hash' => $hash,
                'name' => str_repeat('x', ImportRecords::TEXT_BYTES + 1)]]],
            ['import_record_invalid', [['id' => 1, '_key' => $key, '_hash' => $hash, 'Name' => 'x']]],
            ['import_record_invalid', [['id' => 1, '_key' => $key, 'name' => 'no fingerprint']]],
            ['import_source_order_invalid', [['id' => 1, '_key' => '1', '_hash' => $hash, 'name' => 'x']]],
        ];
        foreach ($refused as [$code, $records]) {
            try {
                $target->apply($job, 0, $identity, $phases, $records);
                self::fail($code . ' accepted');
            } catch (RuntimeException $failure) {
                self::assertSame($code, $failure->getMessage());
            }
        }
        foreach (
            ['import_source_changed' => [$identity, array_reverse($phases), 0],
            'import_checkpoint_conflict' => [$identity, $phases, 5]] as $code => [$who, $order, $revision]
        ) {
            try {
                $target->apply($job, $revision, $who, $order, []);
                self::fail($code . ' accepted');
            } catch (RuntimeException $failure) {
                self::assertSame($code, $failure->getMessage());
            }
        }
        self::assertSame($next, $target->next($job, $identity, $phases));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_map'));
    }

    private function administration(): ImportAdministration
    {
        $secret = new Secret(str_repeat('s', 32));
        $router = RouterFactory::create(dirname(__DIR__, 2), $this->files->path . '/storage');
        return new ImportAdministration(
            $this->db,
            new ImportSources($this->files->path, $secret, $this->db),
            new ImportFileStore($this->files->path . '/storage', $this->files->path . '/public'),
            new InlineAttachments($this->db, $router, 'https://forum.test')
        );
    }

    /** @return array<string, string> The panel's fields for the test's source database. */
    private function sourceFields(string $engine): array
    {
        $host = getenv(TestDatabase::driver() === 'postgresql' ? 'FORUM_TEST_SOURCE_DB_HOST' : 'FORUM_TEST_DB_HOST');
        return ['engine' => $engine, 'host' => (string) $host, 'port' => '3306',
            'database' => (string) getenv('FORUM_TEST_DB_NAME'), 'username' => (string) getenv('FORUM_TEST_DB_USER'),
            'password' => (string) getenv('FORUM_TEST_DB_PASSWORD'), 'prefix' => '', 'charset' => 'UTF-8',
            'attachment_root' => ''];
    }
}
