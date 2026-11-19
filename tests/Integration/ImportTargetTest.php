<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportRecords;
use Nolviko\Modules\Importers\Infrastructure\ImportJobs;
use Nolviko\Modules\Importers\Infrastructure\ImportTarget;
use Nolviko\Modules\Users\Domain\LegacyPassword;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The core side of imports (ADR-038, contract 1) without any source forum: hand-made records go through the import
 * target exactly as the importer extension hands them over. The adapters for SMF, MyBB and phpBB are tested in the
 * extension (extensions-src/nolviko/importer/tests).
 */
final class ImportTargetTest extends TestCase
{
    private const PHASES = ['groups', 'users', 'forums', 'threads', 'posts'];

    private const IDENTITY = ['engine' => 'mybb', 'version' => '1.8.40', 'source_id' => 'contract-test',
        'prefix' => 'old_', 'charset' => 'UTF-8', 'timestamps' => 'unix-utc', 'user_map' => []];

    private Connection $db;

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
        $this->db->insert('users', ['id' => 1, 'username' => 'Owner', 'username_normalized' => 'owner',
            'email' => 'owner@example.test', 'email_normalized' => 'owner@example.test', 'password_hash' => 'untouched',
            'status' => 'active', 'created_at' => '2020-01-01 00:00:00.000000',
            'updated_at' => '2020-01-01 00:00:00.000000']);
        if (TestDatabase::driver() === 'postgresql') {
            $this->db->executeStatement("SELECT setval(pg_get_serial_sequence('users', 'id'), 1)");
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testRecordsBecomeAHiddenForumThatPublishingOpens(): void
    {
        $target = new ImportTarget($this->db);
        $job = $target->begin(self::IDENTITY, [['table' => 'posts', 'mapping' => 'mapped']], self::PHASES, 1, true);
        self::assertSame($job, $target->begin(self::IDENTITY, [], self::PHASES, 1, true));
        $status = $this->drive($target, $job, self::records());
        self::assertTrue($status['complete']);
        self::assertSame($status['processed'], $status['verified']);

        self::assertSame('[b]Çığ[/b] İstanbul', $this->db->fetchOne('SELECT content FROM posts'));
        $member = $this->db->fetchAssociative(
            'SELECT status, password_hash FROM users WHERE username = ?',
            ['Migrated']
        );
        self::assertSame('pending_approval', $member['status']);
        self::assertTrue(LegacyPassword::verify('old-password', $member['password_hash']));
        self::assertSame('suspended', $this->db->fetchOne('SELECT status FROM users WHERE username = ?', ['Unknown']));
        self::assertSame('untouched', $this->db->fetchOne('SELECT password_hash FROM users WHERE id = 1'));
        self::assertSame('İmza', $this->db->fetchOne('SELECT imported_signature FROM member_profiles '
            . 'WHERE user_id = (SELECT id FROM users WHERE username = ?)', ['Migrated']));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM legacy_redirects WHERE entity IN "
            . "('threads', 'posts')"));

        $jobs = new ImportJobs($this->db);
        $report = $jobs->report($job);
        self::assertSame(1, $report['counts']['posts']['verified']);
        self::assertSame(1, $report['notes_total']);
        self::assertSame('password_reset_required', $report['notes'][0]['reason']);
        self::assertSame(self::IDENTITY, $jobs->identity($job));

        $hidden = "SELECT COUNT(*) FROM forum_permissions WHERE permission_key = 'forum.view' AND value = 'never'";
        self::assertGreaterThan(0, (int) $this->db->fetchOne($hidden));
        $jobs->publish($job, true);
        self::assertSame(0, (int) $this->db->fetchOne($hidden));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM users WHERE username = ?', ['Migrated']));
        self::assertSame('suspended', $this->db->fetchOne('SELECT status FROM users WHERE username = ?', ['Unknown']));
    }

    public function testAChangedSourceStopsVerification(): void
    {
        $target = new ImportTarget($this->db);
        $job = $target->begin(self::IDENTITY, [], self::PHASES, 1, true);
        $records = self::records();
        $changed = $records;
        $changed['posts'][0]['_hash'] = str_repeat('f', 64);
        $this->expectExceptionMessage('import_parity_mismatch');
        // First pass with the original, verification with the changed record.
        $this->drive($target, $job, $records, $changed);
    }

    /**
     * The target refuses malformed records, a changed phase list and a stale revision, and leaves the job exactly
     * as it was.
     */
    public function testMalformedBatchesAreRefusedWithoutWriting(): void
    {
        $target = new ImportTarget($this->db);
        $job = $target->begin(self::IDENTITY, [], self::PHASES, 1, true);
        $next = $target->next($job, self::IDENTITY, self::PHASES);
        self::assertSame(['phase' => 'groups', 'cursor' => '', 'revision' => 0, 'complete' => false], $next);
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
                $target->apply($job, 0, self::IDENTITY, self::PHASES, $records);
                self::fail($code . ' accepted');
            } catch (RuntimeException $failure) {
                self::assertSame($code, $failure->getMessage());
            }
        }
        foreach (
            ['import_source_changed' => [array_reverse(self::PHASES), 0],
            'import_checkpoint_conflict' => [self::PHASES, 5]] as $code => [$order, $revision]
        ) {
            try {
                $target->apply($job, $revision, self::IDENTITY, $order, []);
                self::fail($code . ' accepted');
            } catch (RuntimeException $failure) {
                self::assertSame($code, $failure->getMessage());
            }
        }
        self::assertSame($next, $target->next($job, self::IDENTITY, self::PHASES));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_map'));
    }

    /**
     * Drives the target like the extension's runner: ask where to read, hand over the next batch of that phase.
     *
     * @param array<string, list<array<string, mixed>>> $records
     * @param array<string, list<array<string, mixed>>>|null $verification records read in the verification passes
     */
    private function drive(ImportTarget $target, string $job, array $records, ?array $verification = null): array
    {
        $status = $target->read($job);
        for ($step = 0; !$status['complete'] && $step < 200; ++$step) {
            $next = $target->next($job, self::IDENTITY, self::PHASES);
            $source = $verification !== null && $next['revision'] > 0 && $this->verifying($job) ? $verification
                : $records;
            $batch = array_values(array_filter(
                $source[$next['phase']] ?? [],
                static fn(array $row): bool => strcmp($row['_key'], $next['cursor']) > 0
            ));
            $status = $target->apply($job, $next['revision'], self::IDENTITY, self::PHASES, array_slice($batch, 0, 25));
        }
        return $status;
    }

    private function verifying(string $job): bool
    {
        return (int) $this->db->fetchOne('SELECT phase FROM import_jobs WHERE id = ?', [$job])
            >= count(self::PHASES) + 3;
    }

    /** @return array<string, list<array<string, mixed>>> MyBB-shaped records as the extension converts them */
    private static function records(): array
    {
        $record = static function (int $id, array $fields): array {
            $row = ['id' => $id, '_key' => ImportCursor::key([$id])] + $fields;
            return $row + ['_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))];
        };
        return [
            'groups' => [$record(3, ['name' => 'Moderatörler'])],
            'users' => [
                $record(2, ['name' => 'Migrated', 'email' => 'migrated@example.test',
                    'hash' => md5(md5('salt') . md5('old-password')), 'salt' => 'salt', 'group' => 2,
                    'groups' => [2, 3], 'time' => 1700000000, 'signature' => 'İmza', 'source_banned' => 0]),
                $record(4, ['name' => 'Unknown', 'email' => 'unknown@example.test', 'hash' => 'not-a-mybb-hash',
                    'salt' => '', 'group' => 2, 'groups' => [2], 'time' => 1700000000, 'signature' => '',
                    'source_banned' => 0]),
            ],
            'forums' => [
                $record(1, ['name' => 'Kategori', 'description' => '', 'parent' => 0, 'sort' => 1, 'kind' => 'c']),
                $record(2, ['name' => 'Bölüm', 'description' => 'Açıklama', 'parent' => 1, 'sort' => 1,
                    'kind' => 'f']),
            ],
            'threads' => [$record(1, ['forum' => 2, 'user' => 2, 'name' => 'Konu', 'time' => 1700000100,
                'first' => 1, 'locked' => '', 'sticky' => 0, 'visible' => 1])],
            'posts' => [$record(1, ['thread' => 1, 'user' => 2, 'body' => '[b]Çığ[/b] İstanbul',
                'time' => 1700000100, 'visible' => 1])],
        ];
    }
}
