<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Users\Domain\RegistrationRejected;

/** Follow installed FK retention policy in small committed steps; never delete an account with unprocessed children. */
final class AccountDeletionBatch
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Caller holds group, user, deletion-request and fenced queue locks in the same transaction. */
    public function step(int $target, array $request, Closure $guard): bool
    {
        $guard();
        $db = $this->connection;

        $administrator = (int) ($request['protect_admin'] ?? 0) === 1 || $db->fetchOne(
            'SELECT 1 FROM usergroup_members WHERE user_id = ? AND usergroup_id = ?',
            [$target, BuiltInGroup::Administrators->value],
        ) !== false;
        $other = $db->fetchOne(
            'SELECT 1 FROM usergroup_members m INNER JOIN users u ON u.id = m.user_id '
                . "WHERE m.usergroup_id = ? AND m.user_id <> ? AND u.status = 'active' LIMIT 1",
            [BuiltInGroup::Administrators->value, $target],
        );

        if ($administrator && $other === false) {
            throw new RegistrationRejected(['privacy_last_admin']);
        }

        if ($request['processing_started_at'] === null) {
            $plan = $this->plan();
            $guard();
            $db->executeStatement(
                "UPDATE users SET status = 'suspended', session_epoch = session_epoch + 1, "
                    . 'updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
                [$target],
            );
            $db->executeStatement(
                'UPDATE account_deletions SET processing_started_at = UTC_TIMESTAMP(6), cleanup_plan = ?, '
                    . 'protect_admin = ? WHERE user_id = ?',
                [json_encode($plan, JSON_THROW_ON_ERROR), $administrator ? 1 : 0, $target],
            );
        } else {
            $encoded = $request['cleanup_plan'];

            if (!is_string($encoded) || strlen($encoded) > 65536) {
                throw new RegistrationRejected(['privacy_cleanup_review']);
            }

            $plan = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        }

        if (!is_array($plan) || count($plan) > 256) {
            throw new RegistrationRejected(['privacy_cleanup_review']);
        }

        foreach ($plan as $entry) {
            if (!is_array($entry) || count($entry) !== 3) {
                throw new RegistrationRejected(['privacy_cleanup_review']);
            }

            [$table, $column, $action] = $entry;
            self::identifier($table);
            self::identifier($column);

            if (
                !in_array($action, ['CASCADE', 'SET NULL'], true) || $table === 'users'
                || ($table === 'account_deletions' && $column === 'user_id')
            ) {
                throw new RegistrationRejected(['privacy_cleanup_review']);
            }

            $guard();
            $table = $db->quoteIdentifier($table);
            $column = $db->quoteIdentifier($column);
            // At most 100 rows per step; PostgreSQL has no LIMIT on DELETE/UPDATE, so it picks them by ctid.
            $where = SqlDialect::postgres($db)
                ? 'ctid IN (SELECT ctid FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 100)'
                : $column . ' = ? LIMIT 100';
            $sql = $action === 'CASCADE' ? 'DELETE FROM ' . $table . ' WHERE ' . $where
                : 'UPDATE ' . $table . ' SET ' . $column . ' = NULL WHERE ' . $where;
            $changed = $db->executeStatement($sql, [$target]);
            $guard();

            if ($changed > 0) {
                return false;
            }
        }

        // Schema can change between requests. Refresh the plan before final FK work.
        $current = $this->plan();
        $guard();

        if ($current !== $plan) {
            $db->update(
                'account_deletions',
                ['cleanup_plan' => json_encode($current, JSON_THROW_ON_ERROR)],
                ['user_id' => $target]
            );

            return false;
        }

        $db->insert('audit_logs', ['actor_id' => null, 'action' => 'user.deleted', 'target_type' => 'user',
            'target_id' => $target, 'reason' => null, 'details' => '{"source":"durable_batch"}',
            'created_at' => $db->fetchOne('SELECT UTC_TIMESTAMP(6)')]);
        $db->delete('users', ['id' => $target]);
        $guard();

        return true;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function plan(): array
    {
        $plan = [];
        $tables = $this->connection->createSchemaManager()->listTables();

        if (count($tables) > 128) {
            throw new RegistrationRejected(['privacy_cleanup_review']);
        }

        foreach ($tables as $table) {
            foreach ($table->getForeignKeys() as $key) {
                if ($key->getForeignTableName() !== 'users') {
                    continue;
                }

                $local = $key->getLocalColumns();
                $action = strtoupper($key->onDelete() ?? 'NO ACTION');

                if (
                    count($local) !== 1 || $key->getForeignColumns() !== ['id']
                    || !in_array($action, ['CASCADE', 'SET NULL'], true)
                ) {
                    throw new RegistrationRejected(['privacy_cleanup_review']);
                }

                $name = $table->getName();
                self::identifier($name);
                self::identifier($local[0]);

                if ($name === 'account_deletions' && $local[0] === 'user_id') {
                    continue;
                }

                if ($name === 'users') {
                    throw new RegistrationRejected(['privacy_cleanup_review']);
                }

                $plan[] = [$name, $local[0], $action];
            }
        }

        usort($plan, static fn(array $left, array $right): int => $left <=> $right);

        return $plan;
    }

    private static function identifier(string $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new RegistrationRejected(['privacy_cleanup_review']);
        }
    }
}
