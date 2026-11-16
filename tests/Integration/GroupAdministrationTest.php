<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\GroupHandler;
use Nolviko\Modules\Admin\Http\GroupsHandler;
use Nolviko\Modules\Admin\Http\MemberHandler;
use Nolviko\Modules\Admin\Infrastructure\GroupAdministration;
use Nolviko\Modules\Admin\Infrastructure\MemberIdentity;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalResetTokens;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\PasswordRecovery;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** M04.14: group CRUD, membership, rule editing, lock-out guards, audit rows and the resolver effect. */
final class GroupAdministrationTest extends TestCase
{
    private Connection $connection;

    private int $admin;

    private int $member;

    private int $forum;

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
        $users = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $this->admin = $users->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        $this->member = $users->create('Member', 'member@example.com', 'h', UserStatus::Active)->id;
        $groups->addMember($this->admin, BuiltInGroup::Administrators->value, true);
        $groups->addMember($this->admin, BuiltInGroup::Registered->value);
        $groups->addMember($this->member, BuiltInGroup::Registered->value, true);
        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Staff', 'staff')->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testGroupLifecycleMembershipAndAudit(): void
    {
        $admin = $this->subject($this->admin);
        $service = $this->service();
        $group = $service->create($admin, true, 'Helpers', 'helpers');
        $service->rename($admin, true, $group->id, 'Trusted helpers');
        $service->addMember($admin, true, $group->id, 'member');

        self::assertSame('Trusted helpers', $service->find($group->id)?->name);
        self::assertSame(['Member'], array_column($service->members($group->id), 'username'));
        self::assertSame(1, array_column($service->overview(), 'members', 'id')[$group->id]);

        $service->removeMember($admin, true, $group->id, $this->member);
        self::assertSame([], $service->members($group->id));

        foreach (
            [
                ['group_member_unknown', fn() => $service->addMember($admin, true, $group->id, 'nobody')],
                ['group_builtin_protected', fn() => $service->rename(
                    $admin,
                    true,
                    BuiltInGroup::Moderators->value,
                    'X',
                )],
                ['group_self_lockout', fn() => $service->removeMember(
                    $admin,
                    true,
                    BuiltInGroup::Administrators->value,
                    $this->admin,
                )],
                ['group_name_invalid', fn() => $service->rename($admin, true, $group->id, '   ')],
            ] as [$diagnostic, $call]
        ) {
            try {
                $call();
                self::fail($diagnostic . ' accepted');
            } catch (InvalidArgumentException $failure) {
                self::assertSame($diagnostic, $failure->getMessage());
            }
        }

        try {
            $service->removeMember($admin, true, BuiltInGroup::Registered->value, $this->member);
            self::fail('primary membership removed');
        } catch (GroupRuleViolation $violation) {
            self::assertSame('group_primary_required', $violation->diagnostic);
        }

        $this->expectPermissionDenied(fn() => $service->create($admin, false, 'Stale', 'stale'));
        $this->expectPermissionDenied(fn() => $service->create($this->subject($this->member), true, 'Mine', 'mine'));

        $service->delete($admin, true, $group->id);
        self::assertNull($service->find($group->id));
        self::assertSame(
            ['admin.group_created', 'admin.group_renamed', 'admin.group_member_added', 'admin.group_member_removed',
                'admin.group_deleted'],
            array_map('strval', $this->connection->fetchFirstColumn(
                "SELECT action FROM audit_logs WHERE target_type = 'usergroup' ORDER BY id",
            )),
        );
    }

    public function testSavedRulesChangeTheResolvedPermissionAndLockedKeysStay(): void
    {
        $admin = $this->subject($this->admin);
        $service = $this->service();
        $registered = BuiltInGroup::Registered->value;

        self::assertTrue($this->subject($this->member)->allows('forum.thread.create', $this->forum));
        $changed = $service->saveRules($admin, true, $registered, $this->forum, [
            'forum.thread.create' => 'never',
            'forum.post.edit_window_minutes' => '15',
            'forum.post.approval' => 'all',
            'forum.post.reply' => '',
        ]);

        self::assertSame(3, $changed, 'an unchanged inherit is not written');
        self::assertFalse($this->subject($this->member)->allows('forum.thread.create', $this->forum));
        self::assertTrue($this->subject($this->member)->allows('forum.thread.create'), 'only the forum scope changed');
        self::assertSame(['forum.post.approval' => 'all', 'forum.post.edit_window_minutes' => '15',
            'forum.thread.create' => 'never'], $this->sorted($service->rules($registered, $this->forum)));
        self::assertSame(0, $service->saveRules($admin, true, $registered, $this->forum, [
            'forum.thread.create' => 'never',
        ]), 'saving the same values writes nothing');

        $service->saveRules($admin, true, $registered, $this->forum, ['forum.thread.create' => '']);
        self::assertTrue($this->subject($this->member)->allows('forum.thread.create', $this->forum));

        self::assertSame(0, $service->saveRules($admin, true, BuiltInGroup::Administrators->value, null, [
            'admin.access' => 'never',
        ]), 'the administrators\' admin.access rule is locked');
        self::assertTrue($this->subject($this->admin)->allows('admin.access'));
        self::assertSame(0, $service->saveRules($admin, true, BuiltInGroup::Guests->value, null, [
            'admin.access' => 'allow',
        ]), 'guests can never be granted admin.access');

        foreach (
            [['forum.post.edit_window_minutes' => '999999'], ['forum.post.approval' => 'sometimes'],
            ['forum.view' => 'maybe'], ['unknown.key' => 'allow']] as $invalid
        ) {
            try {
                $service->saveRules($admin, true, $registered, null, $invalid);
                self::fail('invalid rule accepted: ' . json_encode($invalid));
            } catch (InvalidArgumentException $failure) {
                self::assertSame('group_rule_invalid', $failure->getMessage());
            }
        }

        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.group_rules_changed'",
        ));
    }

    public function testHttpScreensRequireCsrfAndAFreshSession(): void
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->admin);
        $csrf = new CsrfTokens($session);
        $adminSession = new AdminSession($session);
        $translator = self::translator();
        $views = TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator);
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $subjects = $this->subjects($session);
        $list = new GroupsHandler(
            $this->service(),
            $subjects,
            $adminSession,
            $csrf,
            $session,
            $translator,
            $views,
            $router,
        );
        $detail = new GroupHandler(
            $this->service(),
            PermissionCatalog::core(),
            new DbalForumRepository($this->connection),
            $subjects,
            $adminSession,
            $csrf,
            $session,
            $translator,
            $views,
            $router,
            new ErrorResponder($translator)
        );
        $create = ['action' => 'create', 'name' => 'Editors', 'slug' => 'editors'];

        $page = $list->handle(Request::create('/admin/groups'), []);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Administrators', (string) $page->getContent());
        self::assertSame(403, $list->handle(Request::create('/admin/groups', 'POST', $create), [])->getStatusCode());
        $create[CsrfTokens::FIELD] = $csrf->token();
        self::assertSame(403, $list->handle(Request::create('/admin/groups', 'POST', $create), [])->getStatusCode());

        $adminSession->confirm();
        $created = $list->handle(Request::create('/admin/groups', 'POST', $create), []);
        self::assertSame(303, $created->getStatusCode());
        $id = (int) $this->connection->fetchOne("SELECT id FROM usergroups WHERE slug = 'editors'");
        self::assertSame('/admin/groups/' . $id, $created->headers->get('Location'));

        $editor = $detail->handle(
            Request::create('/admin/groups/' . $id . '?forum=' . $this->forum),
            ['group' => (string) $id],
        );
        $html = (string) $editor->getContent();
        self::assertSame(200, $editor->getStatusCode());
        self::assertStringContainsString('name="rule[forum.thread.create]"', $html);
        self::assertStringContainsString('<option value="' . $this->forum . '" selected>', $html);

        $save = $detail->handle(
            Request::create('/admin/groups/' . $id, 'POST', [CsrfTokens::FIELD => $csrf->token(),
            'action' => 'save_rules', 'forum' => (string) $this->forum, 'rule' => ['forum.view' => 'deny']]),
            ['group' => (string) $id]
        );
        self::assertSame(303, $save->getStatusCode());
        self::assertSame(['forum.view' => 'deny'], $this->service()->rules($id, $this->forum));
        self::assertSame(404, $detail->handle(Request::create('/admin/groups/99999'), ['group' => '99999'])
            ->getStatusCode());
    }

    public function testMemberPageChangesGroupsPrimaryAndSendsAReset(): void
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->admin);
        $csrf = new CsrfTokens($session);
        $adminSession = new AdminSession($session);
        $translator = self::translator();
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $mail = new class implements AccountMail {
            /** @var list<string> */
            public array $resets = [];

            public function verification(string $to, string $username, string $token): void
            {
            }

            public function alreadyRegistered(string $to, string $username): void
            {
            }

            public function passwordReset(string $to, string $username, string $token): void
            {
                $this->resets[] = $to;
            }

            public function emailChangeConfirm(string $to, string $username, string $token): void
            {
            }

            public function emailChangeNotice(string $to, string $username): void
            {
            }
        };
        $handler = new MemberHandler(
            new DbalUserRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            $this->service(),
            new MemberIdentity($this->connection),
            new PasswordRecovery(
                new DbalUserRepository($this->connection),
                new PasswordHasher(),
                new DbalResetTokens($this->connection),
                $mail,
                new RateBuckets($this->connection),
            ),
            $this->subjects($session),
            $adminSession,
            $csrf,
            $session,
            $translator,
            TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            $router,
            new ErrorResponder($translator),
        );
        $post = fn(string $action, int $group = 0) => $handler->handle(Request::create(
            '/admin/members/' . $this->member,
            'POST',
            [CsrfTokens::FIELD => $csrf->token(), 'action' => $action, 'group' => (string) $group],
        ), ['user' => (string) $this->member]);
        $moderators = BuiltInGroup::Moderators->value;

        $page = (string) $handler->handle(Request::create('/admin/members/' . $this->member), [
            'user' => (string) $this->member,
        ])->getContent();
        self::assertStringContainsString('member@example.com', $page);
        self::assertSame(403, $post('add_group', $moderators)->getStatusCode(), 'stale session');

        $adminSession->confirm();
        self::assertSame(303, $post('add_group', $moderators)->getStatusCode());
        self::assertSame(303, $post('primary', $moderators)->getStatusCode());
        self::assertSame($moderators, (new DbalUsergroupRepository($this->connection))->primaryGroupOf($this->member));
        self::assertSame(422, $post('remove_group', $moderators)->getStatusCode(), 'the primary group stays');
        self::assertSame(303, $post('reset_password')->getStatusCode());
        self::assertSame(['member@example.com'], $mail->resets);

        $identity = fn(string $action, string $field, string $value) => $handler->handle(Request::create(
            '/admin/members/' . $this->member,
            'POST',
            [CsrfTokens::FIELD => $csrf->token(), 'action' => $action, $field => $value],
        ), ['user' => (string) $this->member]);
        self::assertSame(422, $identity('rename', 'username', 'x')->getStatusCode(), 'too short');
        self::assertSame(422, $identity('rename', 'username', 'ADMIN')->getStatusCode(), 'taken, case-insensitive');
        self::assertSame(303, $identity('rename', 'username', 'Renamed Member')->getStatusCode());
        self::assertSame(422, $identity('change_email', 'email', 'not-an-email')->getStatusCode());
        self::assertSame(422, $identity('change_email', 'email', 'Admin@Example.com')->getStatusCode(), 'taken');
        $this->connection->insert('email_change_tokens', ['user_id' => $this->member, 'new_email' => 'x@example.com',
            'token_hash' => str_repeat('a', 64), 'expires_at' => '2999-01-01 00:00:00', 'used_at' => null,
            'created_at' => '2026-01-01 00:00:00']);
        self::assertSame(303, $identity('change_email', 'email', 'new@example.com')->getStatusCode());
        $row = $this->connection->fetchAssociative('SELECT username, email FROM users WHERE id = ?', [$this->member]);
        self::assertSame(['username' => 'Renamed Member', 'email' => 'new@example.com'], $row);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = ? AND used_at IS NULL',
            [$this->member],
        ), 'pending email changes are voided');
        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE target_type = 'user' AND target_id = ?",
            [$this->member],
        ));

        $other = (new DbalUserRepository($this->connection))
            ->create('Other Admin', 'other@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))
            ->addMember($other, BuiltInGroup::Administrators->value, true);
        $resets = count($mail->resets);
        foreach (['change_email' => 'email', 'rename' => 'username', 'reset_password' => 'x'] as $action => $field) {
            $refused = $handler->handle(Request::create('/admin/members/' . $other, 'POST', [
                CsrfTokens::FIELD => $csrf->token(), 'action' => $action, $field => 'taker@example.com',
            ]), ['user' => (string) $other]);
            self::assertSame(422, $refused->getStatusCode(), 'another administrator is protected: ' . $action);
        }
        self::assertSame($resets, count($mail->resets));
        self::assertSame(
            'other@example.com',
            $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [$other]),
        );
        self::assertStringNotContainsString('name="email"', (string) $handler->handle(
            Request::create('/admin/members/' . $other),
            ['user' => (string) $other],
        )->getContent());
        self::assertSame(303, $handler->handle(Request::create('/admin/members/' . $this->admin, 'POST', [
            CsrfTokens::FIELD => $csrf->token(), 'action' => 'rename', 'username' => 'Admin Self',
        ]), ['user' => (string) $this->admin])->getStatusCode(), 'administrators change their own account');
        self::assertSame(404, $handler->handle(Request::create('/admin/members/99999'), ['user' => '99999'])
            ->getStatusCode());
    }

    private function expectPermissionDenied(callable $call): void
    {
        try {
            $call();
            self::fail('a change without a fresh administrator session was accepted');
        } catch (PermissionError) {
            self::assertTrue(true);
        }
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    private function sorted(array $rules): array
    {
        ksort($rules);

        return $rules;
    }

    private function service(): GroupAdministration
    {
        $catalog = PermissionCatalog::core();

        return new GroupAdministration(
            $this->connection,
            new DbalUsergroupRepository($this->connection),
            new DbalPermissionRuleStore($this->connection, $catalog),
            $catalog,
        );
    }

    private function subject(int $userId): SubjectPermissions
    {
        $session = new ArraySession();
        $session->set('_user_id', $userId);

        return $this->subjects($session)->of(Request::create('/'));
    }

    private function subjects(ArraySession $session): RequestSubject
    {
        $catalog = PermissionCatalog::core();

        return new RequestSubject(
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
        );
    }

    private static function translator(): MessageTranslator
    {
        return TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
    }
}
