<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

/**
 * Conservative unattended-update subset, not a general SQL parser. Unsupported statements require manual review.
 */
final class ExpandOnlyPolicy
{
    public static function assertAllowed(Migration $migration, string $backend): void
    {
        if ($migration->phase() !== MigrationPhase::Expand) {
            throw new MigrationException('update_migration_phase_forbidden');
        }

        foreach (MigrationRegistry::statements($migration, $backend) as $sql) {
            $scan = self::withoutNewTableReferenceActions($sql);
            // beta.18: an index expression may call the SQL function replace() (PostgreSQL search folding, as in
            // 0033); only the function form inside CREATE INDEX is exempt, never a REPLACE statement.
            if (preg_match('/^\\s*CREATE\\s+(?:UNIQUE\\s+)?INDEX\\b/i', $sql) === 1) {
                $scan = (string) preg_replace('/\\breplace\\s*\\(/i', 'fn(', $scan);
            }
            if (
                strlen($sql) > 1048576
                || str_contains($sql, ';')
                || str_contains($sql, '--')
                || str_contains($sql, '/*')
                || str_contains($sql, '#')
                || preg_match(
                    '/\\b(DROP|TRUNCATE|RENAME|MODIFY|CHANGE|DELETE|UPDATE|REPLACE|INSERT|OUTFILE)\\b/i',
                    $scan,
                ) === 1
                || preg_match('/\\bAS\\s+SELECT\\b/i', $sql) === 1
            ) {
                throw new MigrationException('update_migration_statement_forbidden');
            }

            if (preg_match('/^\\s*ALTER\\s+TABLE\\b/i', $sql) === 1) {
                $tail = preg_replace('/^\\s*ALTER\\s+TABLE\\b/i', '', $sql);

                if (
                    str_contains($sql, ',') || preg_match(
                        '/\\b(ALTER|ENGINE|CONVERT|PARTITION|DISCARD|IMPORT|EXCHANGE|REORGANIZE|DISABLE|ENABLE)\\b/i',
                        $tail,
                    ) === 1
                ) {
                    throw new MigrationException('update_migration_statement_unsupported');
                }
            }

            $identifier = '(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*)';
            $pattern = '~^\s*(?:CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $identifier . '\s*\('
                . '|CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+)?INDEX\s+' . $identifier . '\s+ON\s+'
                . '|ALTER\s+TABLE\s+' . $identifier . '\s+ADD\s+)~i';

            if (preg_match($pattern, $sql) !== 1) {
                throw new MigrationException('update_migration_statement_unsupported');
            }
        }
    }
    /**
     * ON DELETE on a newly created table, or on one foreign key added by ALTER TABLE (2026-11-16), defines future
     * FK behavior; it does not delete existing data.
     */
    private static function withoutNewTableReferenceActions(string $sql): string
    {
        $created = preg_match('/^\s*CREATE\s+TABLE\b/i', $sql) === 1;
        if (
            !$created
            && preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+CONSTRAINT\s+\S+\s+FOREIGN\s+KEY\b/i', $sql) !== 1
        ) {
            return $sql;
        }
        // An existing table never gets CASCADE (a later delete would remove its existing rows), only actions that
        // keep rows: SET NULL, RESTRICT, NO ACTION.
        $actions = $created ? 'SET\s+NULL|CASCADE|RESTRICT|NO\s+ACTION' : 'SET\s+NULL|RESTRICT|NO\s+ACTION';
        $identifier = '(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*)';
        $columns = '\(\s*' . $identifier . '(?:\s*,\s*' . $identifier . ')*\s*\)';
        $reference = '~(\bFOREIGN\s+KEY\s*' . $columns . '\s+REFERENCES\s+' . $identifier . '\s*'
            . $columns . ')\s+ON\s+DELETE\s+(?:' . $actions . ')\b~i';
        return preg_replace($reference, '$1', $sql);
    }
}
