<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalRememberTokens;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.04 remember-me tokens on MySQL 8.4: only hashes stored, rotation on use, expiry, theft detection,
 * revocation, and cascade delete with the user.
 */
final class RememberTokensTest extends TestCase
{
    private Connection $connection;

    private DateTimeImmutable $now;

    private DbalRememberTokens $tokens;

    private int $userId;

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
        $this->now = new DateTimeImmutable('2026-10-31 12:00:00', new DateTimeZone('UTC'));
        $this->tokens = new DbalRememberTokens($this->connection, fn(): DateTimeImmutable => $this->now);
        $users = new DbalUserRepository($this->connection);
        $this->userId = $users->create('deniz', 'deniz@example.com', 'h', UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testOnlyTheValidatorHashIsStoredAndUseRotates(): void
    {
        $cookie = $this->tokens->issue($this->userId);
        [$selector, $validator] = explode(':', $cookie);
        $row = $this->connection->fetchAssociative('SELECT * FROM remember_tokens');

        self::assertIsArray($row);
        self::assertSame($selector, $row['selector']);
        self::assertSame(hash('sha256', $validator), $row['validator_hash']);
        self::assertStringNotContainsString($validator, (string) json_encode($row));
        self::assertSame('2026-11-30 12:00:00.000000', $row['expires_at']);

        $used = $this->tokens->consume($cookie);

        self::assertSame($this->userId, $used[0] ?? null);
        self::assertNotSame($cookie, $used[1] ?? null);
        self::assertNull($this->tokens->consume($cookie), 'single use');
        self::assertSame($this->userId, $this->tokens->consume((string) $used[1])[0] ?? null);
    }

    public function testExpiredTokensAreRejectedAndDeleted(): void
    {
        $cookie = $this->tokens->issue($this->userId);
        $this->now = $this->now->modify('+31 days');

        self::assertNull($this->tokens->consume($cookie));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testWrongValidatorRevokesAllTokensOfThatUser(): void
    {
        $cookie = $this->tokens->issue($this->userId);
        $this->tokens->issue($this->userId);
        $forged = explode(':', $cookie)[0] . ':' . str_repeat('A', 43);

        self::assertNull($this->tokens->consume($forged));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
        self::assertNull($this->tokens->consume('malformed'));
    }

    public function testRevokeRevokeAllAndCascade(): void
    {
        $one = $this->tokens->issue($this->userId);
        $this->tokens->issue($this->userId);
        $this->tokens->revoke($one);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));

        $this->tokens->revokeAll($this->userId);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));

        $this->tokens->issue($this->userId);
        $this->connection->delete('users', ['id' => $this->userId]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
    }
}
