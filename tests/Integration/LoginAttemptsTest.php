<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.04a throttling on MySQL 8.4: binary network keys, window queries on the indexes, clear and prune.
 */
final class LoginAttemptsTest extends TestCase
{
    private Connection $connection;

    private DateTimeImmutable $now;

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
        $this->now = new DateTimeImmutable('2026-10-31 12:00:00.250000', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testThrottleWorksOnTheDatabase(): void
    {
        $attempts = new DbalLoginAttempts($this->connection);
        $throttle = new LoginThrottle($attempts, fn(): DateTimeImmutable => $this->now, static fn(): int => 2);

        for ($i = 0; $i < 5; ++$i) {
            $throttle->record('Deniz', '2001:db8:5:6::' . ($i + 1), false);
        }

        self::assertSame(30, $throttle->retryAfter('deniz', '192.0.2.1'));
        $hourAgo = $this->now->modify('-1 hour');
        $network = (string) LoginThrottle::network('2001:db8:5:6::ff');

        self::assertCount(5, $attempts->failuresForNetwork($network, $hourAgo), 'the same /64');
        self::assertSame(
            '2026-10-31 12:00:00.250000',
            $attempts->failuresForAccount('deniz', $hourAgo)[0]->format('Y-m-d H:i:s.u'),
        );

        $throttle->record('deniz', '192.0.2.1', true);
        self::assertSame(0, $throttle->retryAfter('deniz', '192.0.2.1'));

        $attempts->clearNetwork((string) LoginThrottle::network('2001:db8:5:6::1'));
        $attempts->prune($this->now->modify('+1 second'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM login_attempts'));
    }
}
