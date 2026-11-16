<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Modules\Community\Http\MemberLinkHandler;
use Nolviko\Modules\Community\Http\MemberSuggestHandler;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mention targets and member name suggestions (2026-11-08).
 */
final class MemberLinksTest extends TestCase
{
    private Connection $connection;

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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testMentionTargetsAndSuggestions(): void
    {
        $users = new DbalUserRepository($this->connection);
        $ayse = $users->create('Ayşe Yılmaz', 'ayse@example.com', 'h', UserStatus::Active)->id;
        $users->create('Ayhan', 'ayhan@example.com', 'h', UserStatus::Active);
        $users->create('Aydin', 'aydin@example.com', 'h', UserStatus::Suspended);
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $translator = TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
        $link = new MemberLinkHandler($users, $router, new ErrorResponder($translator));

        $found = $link->handle(Request::create('/members?name=' . rawurlencode('Ayşe Yılmaz')), []);
        self::assertSame(303, $found->getStatusCode());
        self::assertStringEndsWith('?user=' . $ayse, (string) $found->headers->get('Location'));
        self::assertSame(404, $link->handle(Request::create('/members?name=nobody'), [])->getStatusCode());

        $suggest = fn(?int $user, string $q) => (new MemberSuggestHandler($this->connection, $this->subjects($user)))
            ->handle(Request::create('/members/suggest?q=' . rawurlencode($q)), []);
        self::assertSame(403, $suggest(null, 'ay')->getStatusCode(), 'guests get nothing');
        self::assertSame(['Ayhan', 'Ayşe Yılmaz'], json_decode((string) $suggest($ayse, 'AY')->getContent(), true));
        self::assertSame([], json_decode((string) $suggest($ayse, 'a')->getContent(), true), 'two characters');
        self::assertSame([], json_decode((string) $suggest($ayse, '%')->getContent(), true), 'no wildcards');

        $zeynep = $users->create('Zeynep', 'zeynep@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))->addMember($ayse, BuiltInGroup::Registered->value);
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $at = new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC'));
        $thread = (new DbalThreadRepository($this->connection))->create($forum, $zeynep, 'T', 't', $at);
        $posts = new DbalPostRepository($this->connection);
        $posts->add($thread, $zeynep, 'one', 'bbcode', $at);
        $posts->add($thread, $ayse, 'two', 'bbcode', $at->modify('+1 minute'));
        $storage = new TemporaryDirectory();
        $visibility = new ForumVisibility(
            new FileCache($storage->path . '/cache', $storage->path),
            fn(): int => (new VersionCounter($this->connection))->current(VersionCounter::PERMISSION_RULES),
        );
        $inThread = fn(string $q) => json_decode((string) (new MemberSuggestHandler(
            $this->connection,
            $this->subjects($ayse),
            $visibility,
        ))->handle(Request::create('/members/suggest?thread=' . $thread . '&q=' . rawurlencode($q)), [])
            ->getContent(), true);
        self::assertSame(['Ayşe Yılmaz', 'Zeynep'], $inThread(''), 'participants, newest first, right after @');
        self::assertSame(['Zeynep'], $inThread('z'));
        self::assertSame(['Ayşe Yılmaz', 'Ayhan'], $inThread('ay'), 'participants first, then other members');
        $storage->remove();
    }

    private function subjects(?int $user): RequestSubject
    {
        $session = new ArraySession();

        if ($user !== null) {
            $session->set('_user_id', $user);
        }

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
}
