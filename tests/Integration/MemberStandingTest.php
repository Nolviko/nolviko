<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Profile fields, ranks, reputation and badges (2026-11-08).
 */
final class MemberStandingTest extends TestCase
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

    public function testFieldsRanksReputationAndBadges(): void
    {
        $users = new DbalUserRepository($this->connection);
        $alice = $users->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $bob = $users->create('bob', 'bob@example.com', 'h', UserStatus::Active)->id;
        $standing = new MemberStanding($this->connection);

        $standing->addField('Website', 'url');
        $standing->addField('City', 'text');
        [$site, $city] = array_column($standing->fields(), 'id');
        $standing->saveValues($alice, ['field_' . $site => 'https://alice.example', 'field_' . $city => 'Izmir']);
        self::assertSame([$site => 'https://alice.example', $city => 'Izmir'], $standing->values($alice));

        try {
            $standing->saveValues($alice, ['field_' . $site => 'javascript:alert(1)']);
            self::fail('only http(s) links');
        } catch (RegistrationRejected) {
            self::assertSame('https://alice.example', $standing->values($alice)[$site]);
        }

        $standing->addRank('Newcomer', 0, 'neutral');
        $standing->addRank('Regular', 2, 'blue');
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $thread = (new DbalThreadRepository($this->connection))
            ->create($forum, $alice, 'T', 't', new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')));
        $posts = new DbalPostRepository($this->connection);
        $now = new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC'));
        $first = $posts->add($thread, $alice, 'One', 'bbcode', $now);
        $posts->add($thread, $alice, 'Two', 'bbcode', $now);
        $this->connection->insert('post_reactions', ['user_id' => $bob, 'post_id' => $first, 'type_id' => 'like',
            'created_at' => '2026-11-01 11:00:00.000000']);
        $this->connection->insert('post_reactions', ['user_id' => $alice, 'post_id' => $first, 'type_id' => 'like',
            'created_at' => '2026-11-01 11:00:00.000000']);

        $result = $standing->standing([$alice, $bob]);
        self::assertSame(2, $result[$alice]['posts']);
        self::assertSame(1, $result[$alice]['reputation'], 'own reactions do not count');
        self::assertSame('Regular', $result[$alice]['rank']['title'] ?? null);
        self::assertSame('Newcomer', $result[$bob]['rank']['title'] ?? null);
        self::assertSame(['first_post'], MemberStanding::badges(
            $result[$alice],
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-08', new DateTimeZone('UTC')),
        ));
        self::assertContains('veteran', MemberStanding::badges(
            ['posts' => 0, 'reputation' => 0],
            new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC')),
        ));
    }
}
