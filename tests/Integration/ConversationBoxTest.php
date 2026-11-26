<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Modules\Conversations\Domain\ConversationRefused;
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
use Nolviko\Modules\Conversations\Infrastructure\PrivateDrafts;
use Nolviko\Modules\Conversations\Infrastructure\PrivateFolders;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Beta 17 G1: group and hidden recipients, own-box removal, folders, drafts and box capacity on a real engine. */
final class ConversationBoxTest extends TestCase
{
    private const STAMP = '2026-01-01 00:00:00.000000';

    private Connection $db;

    private PrivateConversations $service;

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
        $this->service = new PrivateConversations($this->db, new RateBuckets($this->db), null, 0, 3);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testGroupConversationShowsEveryVisibleParticipantAndAllCanReply(): void
    {
        [$alice, $bob, $carol] = [$this->user('alice'), $this->user('bob'), $this->user('carol')];
        $id = $this->service->start($alice, 'bob, carol', 'Group', 'hello all');

        self::assertSame(0, $this->service->unreadCount($alice));
        self::assertSame(1, $this->service->unreadCount($bob));
        self::assertSame(1, $this->service->unreadCount($carol));
        $newMessage = $this->db->fetchOne('SELECT id FROM private_messages WHERE conversation_id = ?', [$id]);
        self::assertSame((int) $newMessage, (int) $this->db->fetchOne(
            'SELECT last_read_message_id FROM private_conversation_members WHERE conversation_id = ? AND user_id = ?',
            [$id, $alice],
        ));

        foreach ([$alice, $bob, $carol] as $member) {
            $names = array_column($this->service->show($member, $id, null)['participants'], 'name');
            sort($names);
            self::assertSame(['alice', 'bob', 'carol'], $names);
        }

        $this->service->reply($carol, $id, 'carol here');
        self::assertCount(2, $this->service->show($bob, $id, null)['messages']);
        self::assertSame(1, $this->service->unreadCount($alice));
    }

    public function testRecipientLimitAndThrottleApplyPerMessage(): void
    {
        $alice = $this->user('alice');
        array_map(fn(string $name): int => $this->user($name), ['b1', 'b2', 'b3', 'b4']);

        try {
            $this->service->start($alice, 'b1, b2', 'Too many', 'x', 'b3, b4');
            self::fail('limit not applied');
        } catch (ConversationRefused $refused) {
            self::assertSame('conversation_recipients_limit', $refused->diagnostic);
        }

        $throttled = new PrivateConversations($this->db, new RateBuckets($this->db), null, 60, 10);
        $throttled->start($alice, 'b1, b2, b3', 'Three at once', 'one message');
        $this->expectException(RegistrationThrottled::class);
        $throttled->start($alice, 'b4', 'Second', 'too soon');
    }

    public function testHiddenRecipientIsKnownOnlyToTheSenderAndRepliesOnlyToTheSender(): void
    {
        [$alice, $bob, $hidden] = [$this->user('alice'), $this->user('bob'), $this->user('hidden')];
        $id = $this->service->start($alice, 'bob', 'Secret copy', 'hello', 'hidden');

        self::assertSame(['alice', 'bob'], $this->others($bob, $id));
        self::assertContains('hidden', $this->others($alice, $id));
        self::assertTrue($this->service->show($hidden, $id, null)['hidden']);
        self::assertSame(['alice', 'bob', 'hidden'], $this->others($hidden, $id));
        self::assertCount(1, $this->service->inbox($hidden, null));

        $this->service->reply($hidden, $id, 'only for alice');
        self::assertCount(2, $this->service->show($alice, $id, null)['messages']);
        self::assertTrue($this->service->show($alice, $id, null)['messages'][1]['private']);
        self::assertCount(1, $this->service->show($bob, $id, null)['messages']);
        self::assertSame(0, $this->service->unreadCount($bob));

        $this->service->reply($bob, $id, 'bob replies to all');
        self::assertCount(3, $this->service->show($hidden, $id, null)['messages']);
        self::assertSame(['alice', 'bob', 'hidden'], $this->others($hidden, $id));
    }

    public function testRemovingOnlyAffectsTheOwnBoxAndANewMessageBringsItBack(): void
    {
        [$alice, $bob] = [$this->user('alice'), $this->user('bob')];
        $id = $this->service->start($alice, 'bob', 'Remove me', 'first');

        $this->service->remove($bob, $id);
        self::assertSame([], $this->service->inbox($bob, null));
        self::assertNull($this->service->show($bob, $id, null));
        self::assertCount(1, $this->service->inbox($alice, null));
        self::assertCount(1, $this->service->show($alice, $id, null)['messages']);

        $this->service->reply($alice, $id, 'are you there?');
        self::assertCount(1, $this->service->inbox($bob, null));
        self::assertCount(2, $this->service->show($bob, $id, null)['messages']);

        $this->expectException(RegistrationRejected::class);
        $this->service->remove($this->user('outsider'), $id);
    }

    public function testFoldersArePersonalAndLabelLike(): void
    {
        [$alice, $bob] = [$this->user('alice'), $this->user('bob')];
        $folders = new PrivateFolders($this->db);
        $id = $this->service->start($alice, 'bob', 'Filed', 'x');
        $work = $folders->create($alice, 'Work');
        $later = $folders->create($alice, 'Later');
        $unfiled = $folders->create($alice, 'Unfiled');
        $folders->file($alice, $work, $id);
        $folders->file($alice, $later, $id);
        $folders->file($alice, $later, $id);

        self::assertCount(1, $this->service->inbox($alice, null, $work));
        self::assertCount(1, $this->service->inbox($alice, null, $later));
        self::assertSame([], $this->service->inbox($bob, null, $work));
        self::assertSame([
            ['id' => $later, 'name' => 'Later', 'filed' => true],
            ['id' => $unfiled, 'name' => 'Unfiled', 'filed' => false],
            ['id' => $work, 'name' => 'Work', 'filed' => true],
        ], $folders->forConversation($alice, $id));
        self::assertSame([], $folders->forConversation($bob, $id));
        self::assertSame($work, $folders->ensure($alice, 'Work', 'smf:folder:1'));

        foreach ([fn() => $folders->file($bob, $work, $id), fn() => $folders->create($alice, 'work ')] as $bad) {
            try {
                $bad();
                self::fail('accepted a foreign folder or a duplicate name');
            } catch (ConversationRefused $refused) {
                self::assertContains(
                    $refused->diagnostic,
                    ['conversation_folder_invalid', 'conversation_folder_exists'],
                );
            }
        }

        $folders->delete($alice, $work);
        self::assertCount(1, $this->service->inbox($alice, null));
        self::assertSame([$later], $folders->holding($alice, $id));
    }

    public function testDraftsBelongToTheirOwnerAndSurviveSessions(): void
    {
        [$alice, $bob] = [$this->user('alice'), $this->user('bob')];
        $drafts = new PrivateDrafts($this->db);
        $id = $drafts->save($alice, null, 'bob', 'secret', 'Subject', '[b]draft[/b]');
        $drafts->save($alice, $id, 'bob', '', 'Subject 2', 'changed');

        self::assertSame('Subject 2', (new PrivateDrafts($this->db))->find($alice, $id)['subject']);
        self::assertCount(1, $drafts->all($alice));
        self::assertNull($drafts->find($bob, $id));
        $drafts->delete($bob, $id);
        self::assertNotNull($drafts->find($alice, $id));

        $this->expectException(ConversationRefused::class);
        $drafts->save($bob, $id, 'alice', '', 'Hijack', 'x');
    }

    public function testFullBoxRefusesNewConversationsWithoutDeletingTheArchive(): void
    {
        [$alice, $bob, $carol] = [$this->user('alice'), $this->user('bob'), $this->user('carol')];
        $this->db->insert('usergroup_members', ['user_id' => $bob, 'usergroup_id' => 2, 'is_primary' => 1,
            'created_at' => self::STAMP]);
        $this->db->insert('usergroup_permissions', ['usergroup_id' => 2,
            'permission_key' => PrivateConversations::CAPACITY, 'value' => '1']);
        $first = $this->service->start($alice, 'bob', 'Fits', 'x');

        try {
            $this->service->start($carol, 'bob', 'Does not fit', 'y');
            self::fail('full box accepted');
        } catch (ConversationRefused $refused) {
            self::assertSame('conversation_box_full', $refused->diagnostic);
            self::assertSame(['name' => 'bob'], $refused->parameters);
        }

        self::assertNotNull($this->service->show($bob, $first, null));
        $this->service->reply($alice, $first, 'replies still arrive');
        $this->service->remove($bob, $first);
        $this->service->start($carol, 'bob', 'Room again', 'z');
        self::assertCount(1, $this->service->inbox($bob, null));
        self::assertSame(0, PrivateConversations::boxLimit($this->db, $carol));
    }

    /** @return list<string> */
    private function others(int $actor, int $id): array
    {
        $names = array_column($this->service->participants($actor, $id), 'name');
        sort($names);

        return $names;
    }

    private function user(string $name): int
    {
        $this->db->insert('users', ['username' => $name, 'username_normalized' => $name,
            'email' => $name . '@example.com', 'email_normalized' => $name . '@example.com',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return (int) $this->db->lastInsertId();
    }
}
