<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\PermissionSimulatorHandler;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.04: the simulator shows a member's or a guest's value for a permission with the rules that decided it,
 * per forum, and refuses unknown members, forums and permissions.
 */
final class PermissionSimulatorTest extends TestCase
{
    private Connection $connection;

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
        $alice = (new DbalUserRepository($this->connection))
            ->create('Alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))->addMember($alice, BuiltInGroup::Registered->value);
        $this->forum = (new DbalForumRepository($this->connection))
            ->create(ForumType::Forum, 'Staff room', 'staff')->id;
        (new DbalPermissionRuleStore($this->connection, PermissionCatalog::core()))
            ->setGroupRule(BuiltInGroup::Registered->value, 'forum.thread.create', false, $this->forum);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testShowsTheValueAndTheDecidingRules(): void
    {
        $global = $this->check('member=alice&permission=forum.thread.create');
        $inForum = $this->check('member=Alice&permission=forum.thread.create&forum=' . $this->forum);

        self::assertSame(Response::HTTP_OK, $global->getStatusCode());
        self::assertStringContainsString('Result: allowed', (string) $global->getContent());
        self::assertStringContainsString('Result: not allowed', (string) $inForum->getContent());
        self::assertStringContainsString('group:' . BuiltInGroup::Registered->value, (string) $inForum->getContent());
        self::assertStringContainsString('Staff room', (string) $inForum->getContent(), 'forums are offered');

        $guest = (string) $this->check('permission=forum.thread.create')->getContent();
        self::assertStringContainsString('Result: not allowed', $guest);
    }

    public function testUnknownInputIsRefused(): void
    {
        self::assertSame(422, $this->check('member=nobody&permission=forum.thread.create')->getStatusCode());
        self::assertSame(422, $this->check('permission=no.such.permission')->getStatusCode());
        self::assertSame(422, $this->check('permission=forum.thread.create&forum=999999')->getStatusCode());
        $page = $this->check('');
        self::assertSame(Response::HTTP_OK, $page->getStatusCode());
        self::assertStringNotContainsString('Result:', (string) $page->getContent());
    }

    private function check(string $query): Response
    {
        $translator = TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
        $catalog = PermissionCatalog::core(true);
        $handler = new PermissionSimulatorHandler(
            $translator,
            TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            $catalog,
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
            new DbalUserRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            new DbalForumRepository($this->connection),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
        );

        return $handler->handle(Request::create('/admin/permissions?' . $query), []);
    }
}
