<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\RuleSnapshot;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Search\Infrastructure\DbalPostSearch;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Infrastructure\AccountPrivacy;
use Nolviko\Modules\Users\Infrastructure\DbalSessionEpochs;
use Nolviko\Modules\Users\Infrastructure\PersonalDataExport;
use Nolviko\Modules\Users\Infrastructure\ResumableDataExport;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Real-engine regressions for previously unverified search, conversations, workers and privacy exports. */
final class EarlyMilestoneRegressionTest extends TestCase
{
    private const STAMP = '2026-01-01 00:00:00.000000';

    private Connection $db;

    private TemporaryDirectory $scratch;

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
        self::assertTrue(TestDatabase::isDisposable($this->db), 'Refuse a database holding an installed forum.');
        TestDatabase::reset($this->db);
        $this->scratch = new TemporaryDirectory();
        $this->scratch->write('storage/.keep', '');
        $this->scratch->write('public/.keep', '');
    }

    protected function tearDown(): void
    {
        if (isset($this->scratch)) {
            $this->scratch->remove();
        }

        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testSearchScopePrecedesCountsAndNewestPageCut(): void
    {
        $actor = $this->user('alice');
        $public = $this->forum('public');
        $hidden = $this->forum('hidden');
        $thread = $this->thread($public, $actor);
        $this->posts($thread, $actor, 2);
        $this->posts($this->thread($hidden, $actor), $actor, 30);
        $this->posts($this->thread($public, $actor, 'hidden'), $actor, 1);
        $this->posts($thread, $actor, 1, 'pending');
        $result = (new DbalPostSearch($this->db))->find(new ForumScope([$public]), SearchQuery::parse('nolvikoquery'));

        // The MySQL FULLTEXT plan; PostgreSQL's text-search plan is covered by the Portability suite (M08.10).
        if (TestDatabase::driver() !== 'postgresql') {
            $plan = $this->db->fetchAllAssociative(
                'EXPLAIN SELECT p.id FROM posts p INNER JOIN threads t ON t.id = p.thread_id '
                    . "WHERE t.forum_id = ? AND t.status = 'visible' AND p.status = 'visible' "
                    . 'AND MATCH(p.content) AGAINST (? IN BOOLEAN MODE) ORDER BY p.id DESC LIMIT 21',
                [$public, '+nolvikoquery*'],
            );
            $keys = array_values(array_filter(array_column($plan, 'key')));
            self::assertNotEmpty(
                $keys,
                'The optimizer must use an index; forum-first and fulltext-first are both valid.',
            );
            fwrite(STDERR, 'm02_search_index_keys ' . implode(',', $keys) . "\n");
        }


        self::assertSame(2, $result['count']);
        self::assertCount(2, $result['rows']);
        self::assertNull($result['next']);
        self::assertFalse($result['capped']);
        self::assertSame([$public], array_values(array_unique(array_map(
            static fn(array $row): int => (int) $row['forum_id'],
            $result['rows'],
        ))));
        self::assertSame(0, (new DbalPostSearch($this->db))->find(
            new ForumScope([]),
            SearchQuery::parse('nolvikoquery')
        )['count']);
    }

    public function testTurkishCapitalDottedISearchMatchesStoredText(): void
    {
        $user = $this->user('alice');
        $forum = $this->forum('public');
        $thread = $this->thread($forum, $user);
        $this->posts($thread, $user, 1);
        $this->db->executeStatement('UPDATE posts SET content = ? WHERE thread_id = ?', ['Güvenli arama', $thread]);
        $found = (new DbalPostSearch($this->db))->find(new ForumScope([$forum]), SearchQuery::parse('GÜVENLİ'));
        self::assertSame(1, $found['count']);
        self::assertSame('Güvenli arama', $found['rows'][0]['excerpt']);
    }

    public function testConversationMembershipIsRequiredForReadsAndReplies(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $outsider = $this->user('outsider');
        $service = new PrivateConversations($this->db, new RateBuckets($this->db));
        $id = $service->start($alice, 'bob', 'Private subject', '<script>plain text</script>');

        self::assertCount(1, $service->inbox($bob, null));
        self::assertCount(1, $service->show($bob, $id, null)['messages']);
        self::assertSame('<script>plain text</script>', $service->show($bob, $id, null)['messages'][0]['content']);
        self::assertNull($service->show($outsider, $id, null));
        self::assertSame([], $service->inbox($outsider, null));
        $this->expectException(RegistrationRejected::class);
        $service->reply($outsider, $id, 'Intrusion');
    }

    public function testFailedHandlerRollsBackBusinessWritesAndRetainsRetry(): void
    {
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record('fixture.created', 'fixture', 1, []));
        $runner = new CooperativeTaskRunner($this->db, new OutboxQueue($this->db), [
            'fixture.created' => function (OutboxLease $lease, Closure $guard): bool {
                $this->db->insert('settings', ['setting_key' => 'should_rollback', 'setting_value' => '1',
                    'value_type' => 'int', 'updated_at' => self::STAMP]);
                throw new RuntimeException('simulated_crash');
            },
        ], static fn(): bool => true);
        self::assertSame(1, $runner->run()['failed']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM settings'));
        $row = $this->db->fetchAssociative('SELECT status, attempts, owner_token FROM outbox_events');
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['owner_token']);
    }

    public function testLargeDeletionResumesWithoutLosingPostsOrKeepingLogin(): void
    {
        $user = $this->user('alice');
        $thread = $this->thread($this->forum('public'), $user);
        $this->posts($thread, $user, 201);
        $this->deletion($user);
        $privacy = $this->privacy();
        $runner = $this->runner(
            'user.deletion_requested',
            fn(OutboxLease $lease, Closure $guard): bool => $privacy->finalizeScheduled(
                $lease->aggregateId,
                self::STAMP,
                $guard,
            )
        );
        self::assertSame(1, $runner->run(1, 5000)['progress']);
        self::assertNull((new DbalSessionEpochs($this->db))->current($user));

        for (
            $step = 0;
            $step < 100 && $this->db->fetchOne('SELECT id FROM users WHERE id = ?', [$user]) !== false;
            ++$step
        ) {
            $this->due();
            $runner->run(1, 5000);
        }

        self::assertFalse($this->db->fetchOne('SELECT id FROM users WHERE id = ?', [$user]));
        self::assertSame(201, (int) $this->db->fetchOne('SELECT COUNT(*) FROM posts WHERE user_id IS NULL'));
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM outbox_events'));
    }

    public function testLastAdministratorIsNotRemovedByScheduledWork(): void
    {
        $user = $this->user('admin');
        $this->db->insert('usergroup_members', ['user_id' => $user, 'usergroup_id' => 4,
            'is_primary' => 1, 'created_at' => self::STAMP]);
        $this->deletion($user);
        $privacy = $this->privacy();
        $runner = $this->runner(
            'user.deletion_requested',
            fn(OutboxLease $lease, Closure $guard): bool => $privacy->finalizeScheduled(
                $lease->aggregateId,
                self::STAMP,
                $guard,
            )
        );

        self::assertSame(1, $runner->run(1, 5000)['failed']);
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM users WHERE id = ?', [$user]));
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM outbox_events'));
    }

    public function testLargeArchiveSurvivesPartAndFinalRenameCrashesWithoutTruncation(): void
    {
        $user = $this->user('alice');
        $thread = $this->thread($this->forum('public'), $user);
        $this->posts($thread, $user, 2101);
        $exports = $this->exports();
        self::assertNull($this->db->transactional(fn(): ?string => $exports->request($user, $user, true)));
        $partCrash = false;
        $renameCrash = false;
        $runner = $this->runner('user.export_requested', function (
            OutboxLease $lease,
            Closure $guard
        ) use (
            $exports,
            &$partCrash,
            &$renameCrash,
        ): bool {
            $job = json_decode($lease->payload, true)['job'];
            $check = function () use ($guard, &$renameCrash): void {
                $guard();

                $archives = glob($this->scratch->path . '/storage/privacy-jobs/job-*/data.zip') ?: [];

                if (!$renameCrash && $archives !== []) {
                    $renameCrash = true;
                    throw new RuntimeException('after_zip_rename');
                }
            };
            $complete = $exports->step($lease->aggregateId, $job, $check);

            if (!$partCrash) {
                $partCrash = true;
                throw new RuntimeException('after_part_checkpoint');
            }

            return $complete;
        });

        for ($step = 0; $step < 200 && ($exports->progress($user, $user, true)['status'] ?? '') !== 'ready'; ++$step) {
            $this->due();
            $runner->run(1, 5000);
        }

        self::assertTrue($partCrash);
        self::assertTrue($renameCrash);
        self::assertSame('ready', $exports->progress($user, $user, true)['status']);
        $path = $this->db->transactional(fn(): ?string => $exports->request($user, $user, true));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $posts = 0;

        try {
            self::assertTrue(json_decode($zip->getFromName('export.json'), true)['complete']);

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                if (str_starts_with($zip->getNameIndex($index), 'data/posts-')) {
                    $lines = explode("\n", trim($zip->getFromIndex($index)));
                    $posts += count($lines) - 1;
                }
            }
        } finally {
            $zip->close();
        }

        self::assertSame(2101, $posts);
    }

    public function testDeletionGraceCanBeCancelledAndOldWorkCannotDeleteTheAccount(): void
    {
        $user = $this->user('alice');
        $privacy = $this->privacy();
        $subject = $this->subject($user);
        self::assertFalse($privacy->change($subject, $user, false, 'violet harbour lantern 42', 'request'));
        $pending = $privacy->pending($subject, $user, false);
        $window = $this->db->fetchAssociative(
            'SELECT requested_at, delete_after FROM account_deletions WHERE user_id = ?',
            [$user],
        );
        self::assertIsArray($window);
        self::assertSame(14, (new DateTimeImmutable((string) $window['requested_at']))
            ->diff(new DateTimeImmutable((string) $window['delete_after']))->days);
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM outbox_events'));
        $this->db->executeStatement('DELETE FROM rate_limit_buckets');
        self::assertFalse($privacy->change($subject, $user, false, 'violet harbour lantern 42', 'cancel'));
        self::assertNull($privacy->pending($subject, $user, false));
        self::assertTrue($this->db->transactional(fn(): bool => $privacy->finalizeScheduled(
            $user,
            $pending['requested_at'],
            static function (): void {
            },
        )));
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM users WHERE id = ?', [$user]));
        self::assertSame(
            ['user.deletion_requested', 'user.deletion_cancelled'],
            $this->db->fetchFirstColumn('SELECT action FROM audit_logs ORDER BY id')
        );
    }

    public function testPrivacyRejectsWrongPasswordAndOtherMembers(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        foreach ([$alice, $bob] as $target) {
            $this->db->executeStatement('DELETE FROM rate_limit_buckets');

            try {
                $this->privacy()->change($this->subject($alice), $target, false, 'wrong-password', 'request');
                self::fail('unauthorized privacy mutation succeeded');
            } catch (RegistrationRejected $failure) {
                self::assertSame(
                    $target === $alice ? ['current_password_invalid'] : ['privacy_denied'],
                    $failure->diagnostics
                );
            }
        }

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM account_deletions'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testFreshAdminCanRequestDeletionWithAuditButStaleAdminCannot(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('alice');
        $subject = $this->subject($admin, true);

        try {
            $this->privacy()->change($subject, $target, false, '', 'request');
            self::fail('stale admin was accepted');
        } catch (RegistrationRejected $failure) {
            self::assertSame(['privacy_denied'], $failure->diagnostics);
        }

        self::assertFalse($this->privacy()->change($subject, $target, true, '', 'request'));
        self::assertSame($admin, (int) $this->db->fetchOne('SELECT requested_by FROM account_deletions'));
        self::assertSame($admin, (int) $this->db->fetchOne('SELECT actor_id FROM audit_logs'));
        self::assertSame('user.deletion_requested', $this->db->fetchOne('SELECT action FROM audit_logs'));
    }

    public function testSelfExportContainsConversationsButAdminOtherExportExcludesThem(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $admin = $this->user('admin');
        $secret = 'private-message-only-for-participants';
        (new PrivateConversations($this->db, new RateBuckets($this->db)))->start(
            $alice,
            'bob',
            'Private subject',
            $secret,
        );
        $privacy = $this->privacy();
        self::assertNull($privacy->export($this->subject($alice), $alice, false, 'violet harbour lantern 42'));
        self::assertNull($privacy->export($this->subject($admin, true), $alice, true, ''));
        $exports = $this->exports();
        $runner = $this->runner('user.export_requested', static function (
            OutboxLease $lease,
            Closure $guard
        ) use (
            $exports,
        ): bool {
            return $exports->step($lease->aggregateId, json_decode($lease->payload, true)['job'], $guard);
        });

        for ($step = 0; $step < 100; ++$step) {
            $this->due();
            $runner->run(2, 5000);

            if ((int) $this->db->fetchOne("SELECT COUNT(*) FROM outbox_events WHERE status <> 'completed'") === 0) {
                break;
            }
        }

        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM outbox_events WHERE status = 'completed'"));
        $this->db->executeStatement('DELETE FROM rate_limit_buckets');
        $own = $privacy->export($this->subject($alice), $alice, false, 'violet harbour lantern 42');
        $other = $privacy->export($this->subject($admin, true), $alice, true, '');
        self::assertNotNull($own);
        self::assertNotNull($other);
        self::assertStringContainsString($secret, $this->archiveText($own));
        self::assertStringNotContainsString($secret, $this->archiveText($other));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND action = 'user.data_exported'",
            [$admin],
        ));
    }

    private function subject(int $user, bool $admin = false): SubjectPermissions
    {
        return new SubjectPermissions(
            PermissionCatalog::core(),
            new ArrayForumTree([]),
            new RuleSnapshot(user: $admin ? ['admin.access' => '1'] : []),
            [2],
            $user
        );
    }

    private function archiveText(string $path): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $text = '';

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $text .= $zip->getFromIndex($index);
        }

        $zip->close();

        return $text;
    }

    private function user(string $name): int
    {
        $this->db->insert('users', ['username' => $name, 'username_normalized' => $name,
            'email' => $name . '@example.com', 'email_normalized' => $name . '@example.com',
            'password_hash' => (new PasswordHasher())->hash('violet harbour lantern 42'), 'status' => 'active',
            'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return (int) $this->db->lastInsertId();
    }

    private function forum(string $name): int
    {
        $this->db->insert('forums', ['type' => 'forum', 'title' => $name, 'slug' => $name,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return (int) $this->db->lastInsertId();
    }

    private function thread(int $forum, int $user, string $status = 'visible'): int
    {
        $this->db->insert('threads', ['forum_id' => $forum, 'user_id' => $user, 'title' => 'Nolviko fixture',
            'slug' => 'fixture', 'status' => $status, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return (int) $this->db->lastInsertId();
    }

    private function posts(int $thread, int $user, int $count, string $status = 'visible'): void
    {
        while ($count > 0) {
            $size = min(100, $count);
            $values = [];

            for ($index = 0; $index < $size; ++$index) {
                array_push(
                    $values,
                    $thread,
                    $user,
                    'nolvikoquery content',
                    'bbcode',
                    $status,
                    self::STAMP,
                    self::STAMP,
                );
            }

            $this->db->executeStatement(
                'INSERT INTO posts (thread_id, user_id, content, content_format, status, created_at, updated_at)'
                    . ' VALUES '
                    . implode(', ', array_fill(0, $size, '(?, ?, ?, ?, ?, ?, ?)')),
                $values,
            );
            $count -= $size;
        }
    }

    private function deletion(int $user): void
    {
        $this->db->insert('account_deletions', ['user_id' => $user, 'requested_by' => $user,
            'requested_at' => self::STAMP, 'delete_after' => self::STAMP, 'auto_finalize' => 1]);
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record(
            'user.deletion_requested',
            'user',
            $user,
            ['requested_at' => self::STAMP],
        ));
    }

    private function due(): void
    {
        $this->db->executeStatement(
            "UPDATE outbox_events SET available_at = UTC_TIMESTAMP(6) WHERE status = 'pending'",
        );
    }

    private function runner(string $type, Closure $handler): CooperativeTaskRunner
    {
        return new CooperativeTaskRunner(
            $this->db,
            new OutboxQueue($this->db),
            [$type => $handler],
            static fn(): bool => true,
        );
    }

    private function exports(): ResumableDataExport
    {
        return new ResumableDataExport($this->db, $this->scratch->path . '/storage', $this->scratch->path . '/public');
    }

    private function privacy(): AccountPrivacy
    {
        return new AccountPrivacy(
            $this->db,
            new PasswordHasher(),
            new RateBuckets($this->db),
            new PersonalDataExport($this->db, $this->scratch->path . '/storage', $this->scratch->path . '/public')
        );
    }
}
