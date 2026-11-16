<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Users\Domain\IdentityTaken;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.01 on a real MySQL 8.4: the users table from the core migrations, uniqueness by constraint (also
 * under a real race between processes), microsecond UTC timestamps and parameter binding.
 */
final class UserRepositoryTest extends TestCase
{
    private Connection $connection;

    private DbalUserRepository $users;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $now = new DateTimeImmutable('2026-10-31 12:34:56.123456', new DateTimeZone('UTC'));
        $this->users = new DbalUserRepository($this->connection, static fn(): DateTimeImmutable => $now);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testCreateAndFindByNormalizedIdentity(): void
    {
        $status = UserStatus::PendingVerification;
        $user = $this->users->create("\u{0130}smail", ' Ismail@Example.COM ', 'hash-1', $status, 'tr');

        self::assertGreaterThan(0, $user->id);
        self::assertSame("\u{0130}smail", $user->username, 'the display form is kept as entered');
        self::assertSame(UserStatus::PendingVerification, $user->status);
        self::assertSame('2026-10-31 12:34:56.123456', $user->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame($user->id, $this->users->findByUsername('ISMAIL')?->id);
        self::assertSame($user->id, $this->users->findByEmail('ismail@example.com')?->id);
        self::assertNull($this->users->findByUsername("\u{0131}smail"), 'dotless i is a different name');
        self::assertNull($this->users->findById(999_999));
    }

    public function testDuplicatesAreReportedPerField(): void
    {
        $this->users->create('Alice', 'alice@example.com', 'h', UserStatus::Active);

        foreach ([['ALICE', 'other@example.com', 'username'], ['Bob', 'ALICE@example.com', 'email']] as $case) {
            try {
                $this->users->create($case[0], $case[1], 'h', UserStatus::Active);
                self::fail('duplicate accepted: ' . $case[0]);
            } catch (IdentityTaken $taken) {
                self::assertSame($case[2], $taken->field);
            }
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testHostileInputIsStoredVerbatim(): void
    {
        $name = "o'neil\"; DROP TABLE users; --";
        $user = $this->users->create($name, "x'@example.com", 'h', UserStatus::Active);

        self::assertSame($name, $this->users->findById($user->id)?->username);
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['users']));
    }

    public function testUpdatesChangeHashStatusAndTimestamp(): void
    {
        $user = $this->users->create('carol', 'carol@example.com', 'old', UserStatus::PendingVerification);

        self::assertTrue($this->users->updatePasswordHash($user->id, 'new'));
        self::assertTrue($this->users->updateStatus($user->id, UserStatus::Active));
        self::assertFalse($this->users->updateStatus(999_999, UserStatus::Active));
        self::assertSame(['new', UserStatus::Active], [
            $this->users->findById($user->id)?->passwordHash,
            $this->users->findById($user->id)?->status,
        ]);
    }

    public function testSimultaneousRegistrationsWithTheSameNameHaveOneWinner(): void
    {
        $directory = new TemporaryDirectory();
        $go = $directory->path . '/go';
        $processes = [];

        for ($i = 0; $i < 6; ++$i) {
            // Same name in different case and width, different emails: only the constraint can decide.
            $name = $i % 2 === 0 ? 'Racer' : "\u{FF32}ACER";
            $command = [PHP_BINARY, __DIR__ . '/register-worker.php', $go, $name, 'racer' . $i . '@example.com'];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        usleep(300_000);
        touch($go);
        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1]));
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }

        $directory->remove();
        $created = array_filter($results, static fn(string $line): bool => str_starts_with($line, 'created:'));

        self::assertCount(1, $created, implode(', ', $results));
        self::assertSame(5, count(array_keys($results, 'taken:username', true)));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
    }

    private static function connect(): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
    }
}
