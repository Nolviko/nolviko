<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\ForumManagementHandler;
use Nolviko\Modules\Admin\Infrastructure\ForumAdmin;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.05: forums are created, moved, reordered, locked and deleted with plain forms; the tree rules still apply,
 * deleting needs a fresh confirmation and every change is audited.
 */
final class ForumManagementTest extends TestCase
{
    private Connection $connection;

    private DbalForumRepository $forums;

    private ForumAdmin $admin;

    private int $actor;

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
        $this->actor = (new DbalUserRepository($this->connection))
            ->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        $this->forums = new DbalForumRepository($this->connection);
        $this->admin = new ForumAdmin($this->forums, $this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testCreateReorderAndMove(): void
    {
        $general = $this->admin->create($this->actor, ForumType::Category, 'General', 'general', null);
        $news = $this->admin->create($this->actor, ForumType::Forum, 'News', 'news', $general->id);
        $help = $this->admin->create($this->actor, ForumType::Forum, 'Help', 'help', $general->id);
        $chat = $this->admin->create($this->actor, ForumType::Forum, 'Chat', 'chat', $general->id);

        self::assertSame(['News', 'Help', 'Chat'], $this->titles($general->id), 'new forums go last');

        $this->admin->shift($this->actor, $chat->id, -1);
        self::assertSame(['News', 'Chat', 'Help'], $this->titles($general->id));
        $this->admin->shift($this->actor, $news->id, -1);
        self::assertSame(['News', 'Chat', 'Help'], $this->titles($general->id), 'the first stays first');

        $this->admin->moveUnder($this->actor, $help->id, $news->id);
        self::assertSame($news->id, $this->forums->find($help->id)?->parentId);
        self::assertSame('forum_move_into_descendant', $this->violation(
            fn() => $this->admin->moveUnder($this->actor, $news->id, $help->id),
        ));
        self::assertSame('forum_title_invalid', $this->violation(
            fn() => $this->admin->create($this->actor, ForumType::Forum, '   ', 'blank', null),
        ));
        self::assertSame('forum_slug_taken', $this->violation(
            fn() => $this->admin->create($this->actor, ForumType::Forum, 'Again', 'news', null),
        ));
        self::assertSame(6, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE target_type = 'forum'",
        ));
    }

    public function testHandlerLocksAndDeletesOnlyWithConfirmation(): void
    {
        $forum = $this->admin->create($this->actor, ForumType::Forum, 'Old', 'old', null);
        $session = new ArraySession();
        $session->set('_user_id', $this->actor);

        $locked = $this->handler($session, true)->handle($this->post('lock', $forum->id), []);
        self::assertSame(Response::HTTP_SEE_OTHER, $locked->getStatusCode());
        self::assertTrue($this->forums->find($forum->id)?->isLocked);

        $stale = $this->handler($session, true)->handle($this->post('delete', $forum->id), []);
        self::assertSame(Response::HTTP_FORBIDDEN, $stale->getStatusCode());
        self::assertNotNull($this->forums->find($forum->id));

        (new AdminSession($session))->confirm();
        $deleted = $this->handler($session, true)->handle($this->post('delete', $forum->id), []);
        self::assertSame(Response::HTTP_SEE_OTHER, $deleted->getStatusCode());
        self::assertNull($this->forums->find($forum->id));

        $bad = $this->handler($session, true)->handle($this->post('explode', 1), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $bad->getStatusCode());

        $page = (string) $this->handler($session, false)->handle(Request::create('/admin/forums'), [])->getContent();
        self::assertStringContainsString('Top level', $page);
    }

    public function testEditChangesTitleSlugAndDescriptionAndIsAudited(): void
    {
        $forum = $this->admin->create($this->actor, ForumType::Forum, 'Help', 'help', null);
        $this->admin->create($this->actor, ForumType::Forum, 'News', 'news', null);
        $session = new ArraySession();
        $session->set('_user_id', $this->actor);

        $edited = $this->handler($session, true)->handle(Request::create('/admin/forums/action', 'POST', [
            'action' => 'edit', 'forum' => (string) $forum->id, 'title' => ' Support ', 'slug' => 'support',
            'description' => 'Questions about installing the forum.']), []);
        self::assertSame(Response::HTTP_SEE_OTHER, $edited->getStatusCode());
        $saved = $this->forums->find($forum->id);
        self::assertSame(
            ['Support', 'support', 'Questions about installing the forum.'],
            [$saved?->title, $saved?->slug, $saved?->description]
        );

        self::assertSame('forum_slug_taken', $this->violation(
            fn() => $this->admin->update($this->actor, $forum->id, 'Support', 'news', null),
        ));
        self::assertSame('forum_description_invalid', $this->violation(
            fn() => $this->admin->update($this->actor, $forum->id, 'Support', 'support', str_repeat('x', 2001)),
        ));
        $this->admin->update($this->actor, $forum->id, 'Support', 'support', '');
        self::assertNull($this->forums->find($forum->id)?->description, 'an empty description clears it');
        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'forum.update'",
        ));

        $page = (string) $this->handler($session, false)->handle(Request::create('/admin/forums'), [])->getContent();
        self::assertStringContainsString('name="description"', $page);
        self::assertStringContainsString('value="support"', $page);
    }

    private function post(string $action, int $forumId): Request
    {
        return Request::create('/admin/forums/action', 'POST', ['action' => $action, 'forum' => (string) $forumId]);
    }

    /**
     * @return list<string>
     */
    private function titles(int $parentId): array
    {
        return array_values(array_map(
            static fn(Forum $forum): string => $forum->title,
            array_filter($this->forums->all(), static fn(Forum $forum): bool => $forum->parentId === $parentId),
        ));
    }

    private function violation(callable $action): string
    {
        try {
            $action();
        } catch (ForumRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('expected a violation');
    }

    private function handler(ArraySession $session, bool $action): ForumManagementHandler
    {
        $translator = TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
        $catalog = PermissionCatalog::core();

        return new ForumManagementHandler(
            $translator,
            TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                new PermissionResolver(
                    $catalog,
                    new DbalPermissionRuleStore($this->connection, $catalog),
                    new DbalForumTree($this->connection),
                ),
            ),
            $this->forums,
            $this->admin,
            new AdminSession($session),
            new CsrfTokens($session),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
            $action,
        );
    }
}
