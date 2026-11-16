<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Mail\MailMessage;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\CommunityExport;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Community\Infrastructure\CommunityMail;
use Nolviko\Modules\Community\Infrastructure\CommunityMailTemplate;
use Nolviko\Modules\Community\Infrastructure\CommunityNotifications;
use Nolviko\Modules\Community\Infrastructure\CommunityPolls;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Infrastructure\PersonalDataExport;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/** Real-engine privacy matrix, ownership, uniqueness, private files and replay-safe durable notifications. */
final class CommunityTest extends TestCase
{
    private Connection $db;

    private QueryCounter $counter;

    private TemporaryDirectory $files;

    private CommunityAccess $access;

    private MemberProfiles $profiles;

    private CommunityInteractions $actions;

    private CommunityPolls $polls;

    private CommunityNotifications $notifications;

    private PrivateMedia $media;

    private PermissionResolver $resolver;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->counter = new QueryCounter();
        $this->db = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        ), [$this->counter]);

        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('refusing installed forum');
        }

        TestDatabase::reset($this->db);
        $this->files = new TemporaryDirectory();
        $this->files->write('storage/.keep', '');
        $this->files->write('public/.keep', '');
        $now = CommunityInteractions::now();

        foreach ([1 => 'Alice', 2 => 'Bob', 3 => 'Carol'] as $id => $name) {
            $this->db->insert('users', ['id' => $id, 'username' => $name, 'username_normalized' => strtolower($name),
                'email' => $name . '@example.test', 'email_normalized' => strtolower($name) . '@example.test',
                'password_hash' => 'unused', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->db->insert('forums', ['id' => 1, 'type' => 'forum', 'title' => 'Public', 'slug' => 'public',
            'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('threads', ['id' => 1, 'forum_id' => 1, 'user_id' => 1, 'title' => 'Discussion',
            'slug' => 'discussion', 'status' => 'visible', 'created_at' => $now, 'updated_at' => $now]);

        foreach ([1 => 1, 2 => 2] as $post => $author) {
            $this->db->insert('posts', ['id' => $post, 'thread_id' => 1, 'user_id' => $author,
                'content' => 'Post', 'content_format' => 'bbcode', 'status' => 'visible',
                'created_at' => $now, 'updated_at' => $now]);
        }

        $this->db->update('threads', ['first_post_id' => 1, 'last_post_id' => 2], ['id' => 1]);
        $catalog = PermissionCatalog::core(true);
        $this->resolver = new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        );
        $visibility = new ForumVisibility(new FileCache(null), static fn(): int => 1);
        $this->access = new CommunityAccess($this->db, $visibility);
        $this->profiles = new MemberProfiles($this->db, $this->access);
        $this->actions = new CommunityInteractions($this->db, $this->access, $this->profiles);
        $this->polls = new CommunityPolls($this->db, $this->access);
        $this->notifications = new CommunityNotifications($this->db, $this->access, $this->actions);
        $this->media = new PrivateMedia(
            $this->db,
            $this->access,
            $this->profiles,
            new ImageProcessor(),
            $this->files->path . '/storage',
            $this->files->path . '/public'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->files)) {
            $this->files->remove();
        }

        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testProfilePrivacyHasNoAdministratorBypass(): void
    {
        $this->profiles->save($this->user(1), ['visibility' => 'private', 'biography' => '<script>x</script>',
            'signature' => 'private signature']);
        self::assertNull($this->profiles->find($this->resolver->guest(), 1));
        self::assertNull($this->profiles->find($this->user(2), 1));
        self::assertNull($this->profiles->find($this->resolver->user(3, [2, 4]), 1));
        self::assertSame('private signature', $this->profiles->find($this->user(1), 1)['signature']);
        self::assertNotContains(1, array_map(intval(...), array_column(
            $this->profiles->directory($this->user(2), 0, false),
            'id'
        )));
        $this->expectException(RegistrationRejected::class);
        $this->profiles->directory($this->resolver->guest(), 0, false);
    }

    public function testMemberVisibilityAndOnlineOptOut(): void
    {
        $this->profiles->save($this->user(1), ['visibility' => 'members', 'show_online' => '1']);
        self::assertNull($this->profiles->find($this->resolver->guest(), 1));
        self::assertNotNull($this->profiles->find($this->user(2), 1));
        $this->profiles->touch($this->user(1));
        self::assertCount(1, $this->profiles->directory($this->user(2), 0, true));
        $this->profiles->save($this->user(1), ['visibility' => 'members']);
        self::assertCount(0, $this->profiles->directory($this->user(2), 0, true));
    }

    public function testReactionUniquenessRemovalAndSelfDenial(): void
    {
        $this->actions->react($this->user(1), 2, 'like');
        $this->actions->react($this->user(1), 2, 'thanks');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM post_reactions'));
        self::assertSame('thanks', $this->db->fetchOne('SELECT type_id FROM post_reactions'));
        self::assertCount(1, $this->notifications->page($this->user(2)));
        self::assertSame(1, $this->notifications->unreadCount($this->user(2)));
        self::assertSame(0, $this->notifications->unreadCount($this->user(3)));
        $id = (int) $this->db->fetchOne('SELECT id FROM member_notifications');
        $this->notifications->read($this->user(3), $id);
        self::assertNull($this->db->fetchOne('SELECT read_at FROM member_notifications'));
        $this->notifications->read($this->user(2), $id);
        self::assertNotNull($this->db->fetchOne('SELECT read_at FROM member_notifications'));
        self::assertSame(0, $this->notifications->unreadCount($this->user(2)));
        $this->actions->react($this->user(1), 2, '');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM post_reactions'));
        $this->expectException(RegistrationRejected::class);
        $this->actions->react($this->user(1), 1, 'like');
    }

    public function testBlocksStopContactAndFollowingAndCanBeRemoved(): void
    {
        $this->actions->relationship($this->user(1), 'follow', 2, true);
        $this->actions->relationship($this->user(2), 'block', 1, true);
        self::assertFalse($this->profiles->contactAllowed(1, 2));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_follows'));
        self::assertCount(1, $this->profiles->blocks(2));
        self::assertCount(0, $this->notifications->page($this->user(2)));
        self::assertSame(0, $this->notifications->unreadCount($this->user(2)));
        $this->actions->relationship($this->user(2), 'block', 1, false);
        self::assertTrue($this->profiles->contactAllowed(1, 2));
    }

    public function testBookmarkAndSubscriptionAreOwnedAndUnique(): void
    {
        foreach (['bookmark', 'subscribe'] as $kind) {
            $this->actions->relationship($this->user(1), $kind, 1, true);
            $this->actions->relationship($this->user(1), $kind, 1, true);
        }

        self::assertCount(1, $this->actions->bookmarks($this->user(1), 0));
        self::assertCount(0, $this->actions->bookmarks($this->user(2), 0));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM thread_subscriptions'));
        $this->db->update('threads', ['status' => 'hidden'], ['id' => 1]);
        self::assertCount(0, $this->actions->bookmarks($this->user(1), 0));
    }

    public function testPollRejectsDuplicateVotesAndForeignOptions(): void
    {
        $this->polls->create($this->user(1), 1, 'Which?', ['First', 'Second'], false, 1);
        $ids = array_map(intval(...), $this->db->fetchFirstColumn('SELECT id FROM poll_options ORDER BY id'));

        try {
            $this->polls->vote($this->user(2), 1, [999999]);
            self::fail('foreign option accepted');
        } catch (RegistrationRejected) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM poll_ballots'));
        }

        $this->polls->vote($this->user(2), 1, [$ids[0]]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM poll_votes'));
        $this->expectException(RegistrationRejected::class);
        $this->polls->vote($this->user(2), 1, [$ids[1]]);
    }

    public function testProfileRecentPostsAndJoinDateRespectTheReadersScope(): void
    {
        $posts = new DbalPostRepository($this->db);
        $this->db->update('posts', ['status' => 'hidden'], ['id' => 1]);
        $this->db->insert('posts', ['id' => 3, 'thread_id' => 1, 'user_id' => 1, 'content' => 'Visible again',
            'content_format' => 'bbcode', 'status' => 'visible', 'created_at' => '2026-11-07 09:00:00',
            'updated_at' => '2026-11-07 09:00:00']);

        $recent = $posts->latestByUser(new ForumScope([1]), 1, 5);
        self::assertSame([3], array_map(static fn($preview): int => $preview->postId, $recent));
        self::assertSame('Discussion', $recent[0]->threadTitle);
        self::assertSame([], $posts->latestByUser(new ForumScope([]), 1, 5), 'unreadable forum reveals nothing');
        self::assertNotNull($this->profiles->find($this->user(2), 1)['joined_at'] ?? null);
    }

    public function testThreadToolsForTheThreadPageCarryPollStateAndOwnership(): void
    {
        $read = new CommunityRead($this->db, $this->access, $this->profiles);
        $this->polls->create($this->user(1), 1, 'Which?', ['First', 'Second'], false, 1);
        $ids = array_map(intval(...), $this->db->fetchFirstColumn('SELECT id FROM poll_options ORDER BY id'));
        $this->actions->relationship($this->user(2), 'bookmark', 1, true);

        $before = $read->threadTools($this->user(2), 1);
        self::assertSame('Which?', $before['poll']['question']);
        self::assertFalse($before['poll_closed']);
        self::assertSame(1, (int) $before['relationship']['bookmarked']);
        self::assertSame(0, (int) $before['relationship']['voted']);
        self::assertArrayNotHasKey('posts', $before);

        $this->polls->vote($this->user(2), 1, [$ids[0]]);
        self::assertSame(1, (int) $read->threadTools($this->user(2), 1)['relationship']['voted']);
        self::assertSame(['posts', 'extras'], array_values(array_intersect(
            ['posts', 'extras'],
            array_keys($read->thread($this->user(2), 1, 0)),
        )));

        $extras = $read->extras($this->user(1), [1, 2]);
        self::assertTrue($extras[1]['own']);
        self::assertFalse($read->extras($this->user(2), [1])[1]['own']);
    }

    public function testPollOwnerAndClosedPollPolicy(): void
    {
        try {
            $this->polls->create($this->user(2), 1, 'Which?', ['First', 'Second'], true, 0);
            self::fail('non-owner created poll');
        } catch (RegistrationRejected) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM thread_polls'));
        }

        $this->polls->create($this->user(1), 1, 'Which?', ['First', 'Second'], true, 0);
        $this->db->executeStatement('UPDATE thread_polls SET closes_at = CURRENT_TIMESTAMP(6) - INTERVAL \'1\' SECOND');
        $id = (int) $this->db->fetchOne('SELECT MIN(id) FROM poll_options');
        $this->expectException(RegistrationRejected::class);
        $this->polls->vote($this->user(2), 1, [$id]);
    }

    public function testAcceptedAnswerRequiresThreadOwnerAndVisibleReply(): void
    {
        // Only question-and-answer forums have accepted answers (2026-11-14).
        try {
            $this->polls->solution($this->user(1), 1, 2);
            self::fail('answer accepted outside a question and answer forum');
        } catch (RegistrationRejected $refused) {
            self::assertSame(['community_solution_off'], $refused->diagnostics);
        }

        $forum = (int) $this->db->fetchOne('SELECT forum_id FROM threads WHERE id = 1');
        $this->db->update('forums', ['is_questions' => 1], ['id' => $forum]);

        try {
            $this->polls->solution($this->user(2), 1, 2);
            self::fail('reply author accepted answer');
        } catch (RegistrationRejected) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM thread_solutions'));
        }

        $this->polls->solution($this->user(1), 1, 2);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT post_id FROM thread_solutions'));
        $this->polls->solution($this->user(1), 1, 0);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM thread_solutions'));
        $this->expectException(RegistrationRejected::class);
        $this->polls->solution($this->user(1), 1, 1);
    }

    public function testPrivateAttachmentAclAndDeletion(): void
    {
        $id = $this->media->upload($this->user(1), $this->upload('note.txt', 'text/plain', 'notes'), 1, false);
        $response = $this->media->download($this->user(2), $id, false);
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertFalse($response->headers->has('Last-Modified'));
        self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('/public/', $response->getFile()->getPathname());
        $path = $response->getFile()->getPathname();
        $this->media->remove($this->user(1), $id);
        $this->media->collect();
        self::assertFileDoesNotExist($path);
        $this->expectException(RegistrationRejected::class);
        $this->media->download($this->user(2), $id, false);
    }

    public function testHiddenPostRevokesAttachmentAndNotificationAccess(): void
    {
        $id = $this->media->upload($this->user(1), $this->upload('note.txt', 'text/plain', 'notes'), 1, false);
        $this->actions->react($this->user(2), 1, 'like');
        self::assertCount(1, $this->notifications->page($this->user(1)));
        $this->db->update('posts', ['status' => 'hidden'], ['id' => 1]);
        self::assertCount(0, $this->notifications->page($this->user(1)));
        $this->expectException(RegistrationRejected::class);
        $this->media->download($this->user(2), $id, false);
    }

    public function testAvatarPrivacyAndModeratorResetAreSeparate(): void
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);
        $id = $this->media->upload($this->user(1), $this->upload('a.png', 'image/png', $bytes), null, true);
        self::assertSame('image/png', $this->media->download($this->user(2), $id, true)->headers->get('Content-Type'));
        $this->profiles->save($this->user(1), ['visibility' => 'private']);

        try {
            $this->media->download($this->resolver->user(3, [2, 4]), $id, false);
            self::fail('admin bypassed avatar privacy');
        } catch (RegistrationRejected) {
            self::assertTrue(true);
        }

        $this->media->remove($this->resolver->user(3, [2, 4]), $id);
        self::assertSame('avatar.reset', $this->db->fetchOne('SELECT action FROM audit_logs'));
    }

    public function testDurableFanoutSurvivesReplayAndRespectsCurrentPreferences(): void
    {
        $this->actions->relationship($this->user(3), 'subscribe', 1, true);
        $this->db->executeStatement('UPDATE thread_subscriptions SET created_at = created_at - INTERVAL \'1\' DAY');
        $lease = new OutboxLease(99, 'post.created', 2, json_encode(['post_id' => 2, 'thread_id' => 1,
            'user_id' => 2, 'status' => 'visible'], JSON_THROW_ON_ERROR), str_repeat('a', 64), 1, 1);
        $guard = static function (): void {
        };

        foreach ([1, 2] as $_) {
            $this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard));
        }

        self::assertCount(1, $this->notifications->page($this->user(3)));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE event_type = 'community.mail'"
        ));
        $this->profiles->save($this->user(3), ['visibility' => 'everyone']);
        $this->db->transactional(fn() => $this->actions->notify(3, 2, 'reply', 1, 2, 'new'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
    }

    public function testPersonalExportContainsMetadataAndBytesAndActorRecords(): void
    {
        $this->media->upload($this->user(1), $this->upload('note.txt', 'text/plain', 'private bytes'), 1, false);
        $this->actions->relationship($this->user(1), 'bookmark', 1, true);
        $export = new CommunityExport($this->db, $this->files->path . '/storage');
        $data = $export->snapshot(1);
        self::assertCount(1, $data['media']);
        self::assertCount(1, $data['member_bookmarks']);
        $zip = new ZipArchive();
        $path = $this->files->path . '/export.zip';
        $zip->open($path, ZipArchive::CREATE);
        $export->append($zip, $data['files'][0]);
        $zip->close();
        $zip->open($path);
        self::assertSame('private bytes', $zip->getFromName('media/' . $data['files'][0]['key']));
        $zip->close();
    }

    public function testMailIsOptInGenericBoundedAndReplaySafe(): void
    {
        $this->actions->react($this->user(1), 2, 'like');
        $id = (int) $this->db->fetchOne('SELECT id FROM member_notifications');
        $messages = new class implements Mailer {
            public array $sent = [];

            public function send(MailMessage $message): void
            {
                $this->sent[] = $message;
            }
        };
        $mail = new CommunityMail(
            $this->db,
            $messages,
            'https://forum.example.test/subdir',
            RouterFactory::create(dirname(__DIR__, 2), '/missing-cache'),
            new CommunityMailTemplate(dirname(__DIR__, 2), '/missing-cache'),
        );
        $lease = new OutboxLease(100, 'community.mail', $id, '{}', str_repeat('a', 64), 1, 1);
        $guard = static function (): void {
        };
        $this->db->transactional(fn(): bool => $mail->deliver($lease, $guard));
        self::assertCount(0, $messages->sent);
        $this->profiles->save($this->user(2), ['visibility' => 'everyone', 'email_notifications' => '1',
            'notify_reactions' => '1']);
        $this->db->transactional(fn(): bool => $mail->deliver($lease, $guard));
        $this->db->transactional(fn(): bool => $mail->deliver($lease, $guard));
        self::assertCount(1, $messages->sent);
        self::assertStringContainsString(
            'https://forum.example.test/subdir/community/notifications',
            $messages->sent[0]->text
        );
        self::assertStringNotContainsString('Discussion', $messages->sent[0]->text);
        self::assertStringNotContainsString('Alice', $messages->sent[0]->html);
        $this->db->executeStatement('DELETE FROM community_mail_receipts');
        $this->db->executeStatement('UPDATE community_mail_budget SET deliveries = 10');
        self::assertFalse($this->db->transactional(fn(): bool => $mail->deliver($lease, $guard)));
        self::assertCount(1, $messages->sent);
    }

    public function testConversationOptOutAndBlocksApplyToExistingReplies(): void
    {
        $conversations = new PrivateConversations($this->db, new RateBuckets($this->db), $this->profiles);
        $id = $conversations->start(1, 'Bob', 'Private', 'first');
        self::assertSame(0, $conversations->unreadCount(1));
        self::assertSame(1, $conversations->unreadCount(2));
        self::assertTrue((bool) $conversations->inbox(2, null)[0]['unread']);
        self::assertNull($conversations->show(3, $id, null));
        self::assertSame(1, $conversations->unreadCount(2));
        self::assertNotNull($conversations->show(2, $id, null));
        self::assertSame(0, $conversations->unreadCount(2));
        self::assertFalse((bool) $conversations->inbox(2, null)[0]['unread']);
        $this->profiles->save($this->user(1), ['visibility' => 'everyone']);
        $this->expectException(RegistrationRejected::class);
        $conversations->reply(2, $id, 'cannot reply');
    }

    public function testPostExtrasHaveConstantQueryBudgetAndNoPrivateSignatureLeak(): void
    {
        $this->profiles->save($this->user(1), ['visibility' => 'private', 'signature' => 'private signature']);
        $read = new CommunityRead($this->db, $this->access, $this->profiles);
        $subject = $this->user(2);
        $read->extras($subject, [1, 2]);
        $this->counter->count = 0;
        $extras = $read->extras($subject, [1, 2]);
        self::assertSame('', $extras[1]['signature']);
        self::assertNull($extras[1]['profile_id']);
        self::assertLessThanOrEqual(5, $this->counter->count);
        $queries = $this->counter->count;
        $metrics = BenchmarkHarness::measure(fn(): array => $read->extras($subject, [1, 2]), 30, 3);
        fwrite(STDOUT, 'community_extras ' . json_encode(
            ['case' => $metrics, 'sql' => $queries],
            JSON_THROW_ON_ERROR
        ) . "\n");
    }

    public function testResumablePersonalArchiveIncludesPrivateFileBytes(): void
    {
        $this->media->upload($this->user(1), $this->upload('note.txt', 'text/plain', 'archive bytes'), 1, false);
        $exports = (new PersonalDataExport(
            $this->db,
            $this->files->path . '/storage',
            $this->files->path . '/public'
        ))->resumable();
        self::assertNull($this->db->transactional(fn(): ?string => $exports->request(1, 1, true)));
        $payload = json_decode($this->db->fetchOne(
            "SELECT payload FROM outbox_events WHERE event_type = 'user.export_requested'",
        ), true, 8, JSON_THROW_ON_ERROR);
        $guard = static function (): void {
        };
        $ready = false;

        for ($step = 0; $step < 30 && !$ready; ++$step) {
            $ready = $this->db->transactional(fn(): bool => $exports->step(1, $payload['job'], $guard));
        }

        self::assertTrue($ready);
        $path = $this->db->transactional(fn(): ?string => $exports->request(1, 1, true));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $profile = json_decode($zip->getFromName('profile.json'), true, 32, JSON_THROW_ON_ERROR);
        $key = $profile['community']['files'][0]['key'];
        self::assertSame('archive bytes', $zip->getFromName('media/' . $key));
        self::assertTrue(json_decode($zip->getFromName('export.json'), true)['complete']);
        $zip->close();
    }

    public function testRevokedForumPermissionFiltersBeforeListsAndDownload(): void
    {
        $id = $this->media->upload($this->user(1), $this->upload('note.txt', 'text/plain', 'notes'), 1, false);
        $this->db->insert('member_bookmarks', ['user_id' => 3, 'thread_id' => 1,
            'created_at' => CommunityInteractions::now()]);
        $this->actions->react($this->user(1), 2, 'like');
        $this->db->insert('user_permission_overrides', ['user_id' => 3,
            'permission_key' => 'forum.thread.view', 'value' => '0']);
        $catalog = PermissionCatalog::core(true);
        $denied = (new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        ))->user(3, [2]);
        self::assertCount(0, $this->actions->bookmarks($denied, 0));
        $this->expectException(RegistrationRejected::class);
        $this->media->download($denied, $id, false);
    }

    public function testFanoutCheckpointsAfterTwentyFiveRecipientsWithoutDuplicates(): void
    {
        $stamp = '2026-01-01 00:00:00.000000';

        for ($id = 4; $id <= 34; ++$id) {
            $name = 'member' . $id;
            $this->db->insert('users', ['id' => $id, 'username' => $name, 'username_normalized' => $name,
                'email' => $name . '@example.test', 'email_normalized' => $name . '@example.test',
                'password_hash' => 'unused', 'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp]);
            $this->db->insert('thread_subscriptions', ['user_id' => $id, 'thread_id' => 1, 'created_at' => $stamp]);
        }

        $lease = new OutboxLease(101, 'post.created', 2, json_encode(['post_id' => 2, 'thread_id' => 1,
            'user_id' => 2, 'status' => 'visible'], JSON_THROW_ON_ERROR), str_repeat('b', 64), 1, 1);
        $guard = static function (): void {
        };
        self::assertFalse($this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard)));
        self::assertSame(25, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
        self::assertTrue($this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard)));
        self::assertSame(31, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
        self::assertTrue($this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard)));
        self::assertSame(31, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
    }

    public function testMentionsNotifyNamedMembersOncePerPost(): void
    {
        $three = (string) $this->db->fetchOne('SELECT username FROM users WHERE id = 3');
        $two = (string) $this->db->fetchOne('SELECT username FROM users WHERE id = 2');
        $this->db->update('posts', ['content' => 'Hi @"' . mb_strtoupper($three) . '", @' . $two
            . ' and @nobody_here [quote]@"' . $three . '"[/quote]'], ['id' => 2]);
        $lease = new OutboxLease(104, 'post.created', 2, json_encode(['post_id' => 2, 'thread_id' => 1,
            'user_id' => 2, 'status' => 'visible'], JSON_THROW_ON_ERROR), str_repeat('e', 64), 1, 1);
        $guard = static function (): void {
        };

        $this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard));
        $this->db->executeStatement('DELETE FROM community_fanout');
        $this->db->transactional(fn(): bool => $this->notifications->fanout($lease, $guard));

        self::assertSame(
            [['user_id' => 3, 'kind' => 'mention']],
            array_map(
                static fn(array $row): array => ['user_id' => (int) $row['user_id'], 'kind' => $row['kind']],
                $this->db->fetchAllAssociative("SELECT user_id, kind FROM member_notifications WHERE kind = 'mention'")
            ),
            'the author is never notified of their own mention, and a retry adds nothing',
        );
    }

    public function testPendingContentNotifiesOnlyAfterApprovalWithTheActualAuthor(): void
    {
        $this->actions->relationship($this->user(3), 'subscribe', 1, true);
        $this->db->executeStatement('UPDATE thread_subscriptions SET created_at = created_at - INTERVAL \'1\' DAY');
        $this->db->update('posts', ['status' => 'pending'], ['id' => 2]);
        $created = new OutboxLease(102, 'post.created', 2, json_encode(['post_id' => 2, 'thread_id' => 1,
            'user_id' => 2, 'status' => 'pending'], JSON_THROW_ON_ERROR), str_repeat('c', 64), 1, 1);
        $guard = static function (): void {
        };
        $this->db->transactional(fn(): bool => $this->notifications->fanout($created, $guard));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
        $this->db->update('posts', ['status' => 'visible'], ['id' => 2]);
        $approved = new OutboxLease(103, 'post.approved', 2, json_encode(['post_id' => 2, 'thread_id' => 1,
            'actor_id' => 1, 'previous_status' => 'pending'], JSON_THROW_ON_ERROR), str_repeat('d', 64), 1, 1);
        $this->db->transactional(fn(): bool => $this->notifications->fanout($approved, $guard));
        $this->db->transactional(fn(): bool => $this->notifications->fanout($approved, $guard));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM member_notifications'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT actor_id FROM member_notifications'));
        self::assertCount(1, $this->notifications->page($this->user(3)));
    }

    public function testAttachmentUploadCannotBypassPostEditWindowLockOrPermission(): void
    {
        // The default has no time limit (2026-11-09); a board that sets one still binds attachments.
        $this->db->executeStatement("UPDATE usergroup_permissions SET value = '60' "
            . "WHERE usergroup_id = 2 AND permission_key = 'forum.post.edit_window_minutes'");
        $this->db->executeStatement('UPDATE posts SET created_at = created_at - INTERVAL \'2\' DAY WHERE id = 1');

        try {
            $this->media->upload($this->user(1), $this->upload('expired.txt', 'text/plain', 'text'), 1, false);
            self::fail('expired post accepted an attachment');
        } catch (RegistrationRejected) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM community_media'));
        }

        $this->db->executeStatement('UPDATE posts SET created_at = CURRENT_TIMESTAMP(6) WHERE id = 1');
        $this->db->update('threads', ['is_locked' => 1], ['id' => 1]);

        try {
            $this->media->upload($this->user(1), $this->upload('locked.txt', 'text/plain', 'text'), 1, false);
            self::fail('locked thread accepted an attachment');
        } catch (RegistrationRejected) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM community_media'));
        }

        $this->db->update('threads', ['is_locked' => 0], ['id' => 1]);
        $this->db->insert('user_permission_overrides', ['user_id' => 1,
            'permission_key' => 'forum.post.edit_own', 'value' => '0']);
        $catalog = PermissionCatalog::core(true);
        $denied = (new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        ))->user(1, [2]);
        $this->expectException(RegistrationRejected::class);
        $this->media->upload($denied, $this->upload('denied.txt', 'text/plain', 'text'), 1, false);
    }

    private function user(int $id): SubjectPermissions
    {
        return $this->resolver->user($id, [2]);
    }

    private function upload(string $name, string $mime, string $bytes): UploadedFile
    {
        $path = $this->files->write('upload-' . bin2hex(random_bytes(8)), $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
