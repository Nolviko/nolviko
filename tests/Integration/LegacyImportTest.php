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
use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\ImportRunner;
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
}
