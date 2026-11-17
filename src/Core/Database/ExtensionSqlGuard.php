<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nolviko\Core\Extensions\ExtensionFailure;

/**
 * The database connection an extension gets (owner request 2026-11-17: an extension must not be able to change or
 * damage the forum's own tables, so turning it off can never leave the site broken).
 *
 * Reading is free. Writing (INSERT/UPDATE/DELETE/REPLACE) is allowed only into the extension's own tables
 * (`ext_<hash>_*`, created by its declarative migrations) and, append-only, into `audit_logs`. Schema and server
 * statements (ALTER, DROP, CREATE, TRUNCATE, RENAME, GRANT, LOCK, CALL, LOAD, SET, ...) and several statements in
 * one string are refused. Every path is checked: prepare(), query() and exec().
 *
 * This guards against careless or accidental damage. Extensions remain trusted PHP code: one that is written to
 * misbehave can still open its own connection, which is why installing one needs the administrator's trust.
 */
final class ExtensionSqlGuard implements Middleware
{
    private const READS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    private const TRANSACTIONS = ['SAVEPOINT', 'RELEASE', 'ROLLBACK', 'START', 'BEGIN', 'COMMIT'];

    private const APPEND_ONLY = ['audit_logs'];

    public function __construct(private readonly string $prefix)
    {
    }

    /** A second, guarded connection with the base connection's settings; it connects only when first used. */
    public static function connection(Connection $base, string $prefix): Connection
    {
        return new Connection(
            $base->getParams(),
            (new self($prefix))->wrap($base->getDriver()),
            $base->getConfiguration(),
        );
    }

    public function wrap(Driver $driver): Driver
    {
        $prefix = $this->prefix;

        return new class ($driver, $prefix) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $prefix)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->prefix) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly string $prefix)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ExtensionSqlGuard::check($sql, $this->prefix);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        ExtensionSqlGuard::check($sql, $this->prefix);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        ExtensionSqlGuard::check($sql, $this->prefix);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    /**
     * @throws ExtensionFailure `extension_sql_refused:<keyword>`
     */
    public static function check(string $sql, string $prefix): void
    {
        // Quoted literals and comments cannot hide a second statement or a keyword.
        $plain = (string) preg_replace(
            ['~/\*.*?\*/~s', '~(--|#)[^\n]*~', "~'(?:[^'\\\\]|\\\\.|'')*'~s", '~"(?:[^"\\\\]|\\\\.)*"~s'],
            [' ', ' ', "''", '""'],
            $sql,
        );
        $plain = trim($plain);
        if (str_contains(rtrim($plain, "; \t\r\n"), ';')) {
            throw new ExtensionFailure('extension_sql_refused:multiple');
        }

        $keyword = strtoupper((string) strtok(ltrim($plain, "( \t\r\n"), " \t\r\n("));
        if (in_array($keyword, self::READS, true) || in_array($keyword, self::TRANSACTIONS, true)) {
            return;
        }
        if ($keyword === 'WITH') {
            // A common table expression is a read unless it hides a write.
            if (preg_match('~\b(INSERT|UPDATE|DELETE|REPLACE|MERGE)\b~i', $plain) === 1) {
                throw new ExtensionFailure('extension_sql_refused:with');
            }

            return;
        }

        $target = match ($keyword) {
            'INSERT', 'REPLACE' => '~^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?([`"]?[A-Za-z0-9_.]+[`"]?)~i',
            'UPDATE' => '~^UPDATE\s+(?:IGNORE\s+)?([`"]?[A-Za-z0-9_.]+[`"]?)~i',
            'DELETE' => '~^DELETE\s+(?:IGNORE\s+)?FROM\s+([`"]?[A-Za-z0-9_.]+[`"]?)~i',
            default => throw new ExtensionFailure('extension_sql_refused:' . strtolower($keyword ?: 'empty')),
        };
        if (preg_match($target, $plain, $match) !== 1) {
            throw new ExtensionFailure('extension_sql_refused:' . strtolower($keyword));
        }
        // Multi-table UPDATE/DELETE (MySQL joins, comma lists, USING) could write a forum table through the
        // extension's own table: only a single target is accepted.
        if (in_array($keyword, ['UPDATE', 'DELETE'], true)) {
            $head = (string) preg_split('~\b(?:SET|WHERE|ORDER|LIMIT|RETURNING)\b~i', $plain, 2)[0];
            if (preg_match('~\bJOIN\b|,|\bUSING\b~i', $head) === 1) {
                throw new ExtensionFailure('extension_sql_refused:multi-table');
            }
        }
        $table = strtolower(trim($match[1], '`"'));
        $table = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        $own = str_starts_with($table, strtolower($prefix));
        $appendOnly = $keyword === 'INSERT' && in_array($table, self::APPEND_ONLY, true);
        if (!$own && !$appendOnly) {
            throw new ExtensionFailure('extension_sql_refused:' . strtolower($keyword) . ':' . $table);
        }
    }
}
