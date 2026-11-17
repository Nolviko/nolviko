<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Database;

use Doctrine\DBAL\DriverManager;
use Nolviko\Core\Database\ExtensionSqlGuard;
use Nolviko\Core\Extensions\ExtensionFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An extension's database connection cannot change or write the forum's own tables (2026-11-17). */
final class ExtensionSqlGuardTest extends TestCase
{
    private const PREFIX = 'ext_0123456789abcdef0123456789abcdef_';

    /** @return iterable<string, array{0: string}> */
    public static function allowed(): iterable
    {
        yield 'read core' => ['SELECT id, username FROM users WHERE id = ?'];
        yield 'read with cte' => ['WITH x AS (SELECT 1) SELECT * FROM x'];
        yield 'write own' => ['INSERT INTO ' . self::PREFIX . 'meta (page_key) VALUES (?)'];
        yield 'update own' => ['UPDATE ' . self::PREFIX . 'redirects SET hits = hits + 1 WHERE source = ?'];
        yield 'delete own' => ['DELETE FROM `' . self::PREFIX . 'meta` WHERE page_key = ?'];
        yield 'audit append' => ['INSERT INTO audit_logs (actor_id, action) VALUES (?, ?)'];
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2'];
        yield 'semicolon in a string' => ["SELECT * FROM users WHERE bio = 'a; DROP TABLE users'"];
        yield 'trailing semicolon' => ['SELECT 1;'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function refused(): iterable
    {
        yield 'alter core' => ['ALTER TABLE users ADD COLUMN x INT'];
        yield 'drop core' => ['DROP TABLE posts'];
        yield 'create' => ['CREATE TABLE anything (id INT)'];
        yield 'truncate' => ['TRUNCATE TABLE ' . self::PREFIX . 'meta'];
        yield 'rename' => ['RENAME TABLE users TO old_users'];
        yield 'write core' => ['UPDATE users SET username = ? WHERE id = ?'];
        yield 'delete core' => ['DELETE FROM posts WHERE id = ?'];
        yield 'insert core' => ['INSERT INTO users (username) VALUES (?)'];
        yield 'audit is append-only' => ['DELETE FROM audit_logs'];
        yield 'second statement' => ['SELECT 1; DROP TABLE users'];
        yield 'comment hides nothing' => ['/* SELECT */ DROP TABLE users'];
        yield 'join update' => ['UPDATE ' . self::PREFIX . 'meta m JOIN users u ON u.id = m.id SET u.username = ?'];
        yield 'comma update' => ['UPDATE ' . self::PREFIX . 'meta, users SET users.username = ?'];
        yield 'cte write' => ['WITH x AS (DELETE FROM users RETURNING id) SELECT * FROM x'];
        yield 'session settings' => ["SET sql_mode = ''"];
        yield 'quoted core name' => ['UPDATE `users` SET username = ?'];
        yield 'schema qualified' => ['DELETE FROM forum.users WHERE id = 1'];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $sql): void
    {
        ExtensionSqlGuard::check($sql, self::PREFIX);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('refused')]
    public function testRefused(string $sql): void
    {
        $this->expectException(ExtensionFailure::class);
        ExtensionSqlGuard::check($sql, self::PREFIX);
    }

    public function testEveryPathOfARealConnectionIsGuarded(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'guard');
        $base = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $base->executeStatement('CREATE TABLE users (id INTEGER, username TEXT)');
        $base->executeStatement('CREATE TABLE ' . self::PREFIX . 'meta (page_key TEXT)');
        $base->insert('users', ['id' => 1, 'username' => 'alice']);
        $guarded = ExtensionSqlGuard::connection($base, self::PREFIX);

        $guarded->insert(self::PREFIX . 'meta', ['page_key' => 'home']);
        self::assertSame('home', $guarded->fetchOne('SELECT page_key FROM ' . self::PREFIX . 'meta'));
        self::assertSame('alice', $guarded->fetchOne('SELECT username FROM users WHERE id = 1'));

        $attempts = [
            static fn() => $guarded->executeStatement('ALTER TABLE users ADD COLUMN x INT'),
            static fn() => $guarded->update('users', ['username' => 'mallory'], ['id' => 1]),
            static fn() => $guarded->executeQuery('DELETE FROM users'),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('a forum table was changed through the extension connection');
            } catch (ExtensionFailure $failure) {
                self::assertStringStartsWith('extension_sql_refused', $failure->getMessage());
            }
        }
        self::assertSame('alice', $base->fetchOne('SELECT username FROM users WHERE id = 1'), 'core data untouched');
        $guarded->close();
        $base->close();
        @unlink($file);
    }
}
