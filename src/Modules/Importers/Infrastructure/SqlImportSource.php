<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Importers\Domain\ImportAsset;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportSource;
use Nolviko\Modules\Importers\Domain\ImportText;
use RuntimeException;

/** Dedicated read-only transaction per bounded batch. Owner must freeze the source (including MyISAM tables). */
final class SqlImportSource implements ImportSource
{
    public function __construct(
        private readonly Connection $source,
        private readonly string $engine,
        private readonly string $prefix,
        private readonly string $sourceId,
        private readonly string $charset = 'UTF-8',
        private readonly array $attachmentRoots = [],
        private readonly array $userMap = [],
    ) {
        if (
            !isset(SourceSchema::SPECS[$engine]) || preg_match('/^[A-Za-z0-9_]{0,48}$/D', $prefix) !== 1
            || preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $sourceId) !== 1
            || !in_array($charset, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)
            || $source->isTransactionActive()
        ) {
            throw new RuntimeException('import_source_invalid');
        }
        $source->executeStatement('SET NAMES binary');
    }

    public function phases(): array
    {
        return SourceSchema::phases($this->engine);
    }

    public function identity(): array
    {
        $version = $this->readonly(function (): mixed {
            return match ($this->engine) {
                'smf' => $this->source->fetchOne('SELECT value FROM ' . $this->table('settings')
                    . " WHERE variable = 'smfVersion'"),
                'phpbb' => $this->source->fetchOne('SELECT config_value FROM ' . $this->table('config')
                    . " WHERE config_name = 'version'"),
                'mybb' => $this->mybbVersion(),
            };
        });
        $pattern = match ($this->engine) {
            'smf' => '/^2\.1\.[0-9]{1,3}$/D',
            'mybb' => '/^1\.8\.[0-9]{1,3}$/D',
            'phpbb' => '/^3\.3\.[0-9]{1,3}$/D',
        };
        if (!is_string($version) || preg_match($pattern, $version) !== 1) {
            throw new RuntimeException('import_source_version_unsupported');
        }
        return ['engine' => $this->engine, 'version' => $version, 'source_id' => $this->sourceId,
            'prefix' => $this->prefix, 'charset' => $this->charset, 'timestamps' => 'unix-utc',
                'user_map' => $this->userMap];
    }

    public function coverage(): array
    {
        $tables = $this->readonly(fn(): array => $this->source->fetchAllAssociative(
            'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows FROM '
                . 'information_schema.tables WHERE table_schema = DATABASE() '
                . 'ORDER BY table_name LIMIT 513',
        ));
        if (count($tables) > 512) {
            throw new RuntimeException('import_source_schema_budget');
        }
        $mapped = array_column(SourceSchema::SPECS[$this->engine], 0);
        $mapped = array_merge($mapped, match ($this->engine) {
            'smf' => ['personal_messages', 'pm_recipients', 'attachments', 'polls', 'poll_choices', 'log_polls'],
            'mybb' => ['privatemessages', 'attachments', 'polls', 'pollvotes'],
            'phpbb' => ['privmsgs', 'privmsgs_to', 'attachments', 'poll_options', 'poll_votes', 'user_group'],
        });
        $coverage = [];
        foreach ($tables as $table) {
            $name = (string) $table['table_name'];
            if (!str_starts_with($name, $this->prefix)) {
                continue;
            }
            $base = substr($name, strlen($this->prefix));
            $coverage[] = ['table' => $base, 'estimated_rows' => (int) $table['table_rows'],
                'mapping' => in_array($base, $mapped, true) ? 'mapped' : 'not_imported'];
        }
        return $coverage;
    }

    public function rows(string $phase, string $after, int $limit): array
    {
        if ($limit < 1 || $limit > 25 || !in_array($phase, $this->phases(), true)) {
            throw new RuntimeException('import_batch_invalid');
        }
        return $this->readonly(function () use ($phase, $after, $limit): array {
            if (!isset(SourceSchema::SPECS[$this->engine][$phase])) {
                return (new SourceAuxRows($this->source, $this->engine, $this->prefix, $this->charset))
                    ->rows($phase, $after, $limit);
            }
            [$table, $key, $fields] = SourceSchema::SPECS[$this->engine][$phase];
            $select = ['s.' . $key . ' AS id'];
            foreach ($fields as $alias => $column) {
                $select[] = 'CASE WHEN OCTET_LENGTH(s.' . $column . ') <= 131072 THEN s.' . $column
                    . ' ELSE NULL END AS ' . $this->source->quoteIdentifier($alias);
            }
            $join = '';
            if ($this->engine === 'smf' && $phase === 'threads') {
                $join = ' LEFT JOIN ' . $this->table('messages') . ' m ON m.id_msg = s.id_first_msg';
                $select[] = 'm.subject AS name';
                $select[] = 'm.poster_time AS time';
            }
            if ($this->engine === 'mybb' && $phase === 'users') {
                $select[] = 'EXISTS (SELECT 1 FROM ' . $this->table('usergroups') . ' g WHERE g.isbannedgroup = 1 '
                    . "AND FIND_IN_SET(g.gid, CONCAT(s.usergroup, ',', s.additionalgroups))) AS source_banned";
            }
            $rows = $this->source->fetchAllAssociative('SELECT ' . implode(', ', $select) . ' FROM '
                . $this->table($table) . ' s' . $join . ' WHERE s.' . $key . ' > ? ORDER BY s.' . $key
                . ' LIMIT ' . $limit, [ImportCursor::parts($after)[0]]);
            $memberships = [];
            if ($this->engine === 'phpbb' && $phase === 'users' && $rows !== []) {
                $links = $this->source->fetchAllAssociative(
                    'SELECT user_id, group_id FROM '
                    . $this->table('user_group') . ' WHERE user_id IN (?) AND user_pending = 0 '
                    . 'ORDER BY user_id, group_id LIMIT 2501',
                    [array_column($rows, 'id')],
                    [ArrayParameterType::INTEGER]
                );
                if (count($links) > 2500) {
                    throw new RuntimeException('import_membership_budget');
                }
                foreach ($links as $link) {
                    $memberships[(int) $link['user_id']][] = (int) $link['group_id'];
                }
            }
            foreach ($rows as &$row) {
                if (in_array(null, $row, true)) {
                    throw new RuntimeException('import_source_row_oversize');
                }
                $row['id'] = (int) $row['id'];
                $row['_key'] = ImportCursor::key([$row['id']]);
                foreach ($fields as $alias => $column) {
                    if (in_array($alias, ['name', 'description', 'email', 'body', 'signature'], true)) {
                        $row[$alias] = ImportText::decode((string) $row[$alias], $this->charset);
                    }
                }
                if ($this->engine === 'smf' && $phase === 'threads') {
                    $row['name'] = ImportText::decode((string) $row['name'], $this->charset);
                }
                if ($phase === 'users') {
                    $ids = $memberships[$row['id']] ?? explode(',', (string) ($row['groups'] ?? ''));
                    $ids[] = $row['group'];
                    $row['groups'] = array_values(array_unique(array_filter(
                        array_map(intval(...), $ids),
                        static fn(int $id): bool => $id > 0
                    )));
                    sort($row['groups'], SORT_NUMERIC);
                    if (count($row['groups']) > 100) {
                        throw new RuntimeException('import_membership_budget');
                    }
                }
            }
            unset($row);
            return $rows;
        });
    }

    public function asset(array $row): ?ImportAsset
    {
        $folder = (string) ($row['folder'] ?? '1');
        $root = $this->attachmentRoots[$folder] ?? null;
        if (!is_string($root)) {
            throw new RuntimeException('import_attachment_root_required');
        }
        $stored = (string) ($row['stored'] ?? '');
        if ($this->engine === 'smf') {
            if (preg_match('/^[a-f0-9]{40}$/D', $stored) !== 1) {
                throw new RuntimeException('import_attachment_name_invalid');
            }
            $stored = $row['id'] . '_' . $stored . '.dat';
        } elseif ($this->engine === 'mybb') {
            if (preg_match('~^(?:[0-9]{6}/)?post_[A-Za-z0-9_]{1,180}\.attach$~D', $stored) !== 1) {
                throw new RuntimeException('import_attachment_name_invalid');
            }
        } elseif (preg_match('/^[0-9]+_[a-f0-9]{32}(?:\.[A-Za-z0-9]{1,10})?$/D', $stored) !== 1) {
            throw new RuntimeException('import_attachment_name_invalid');
        }
        return new LocalImportAsset($root, $stored, (int) $row['bytes']);
    }

    private function mybbVersion(): mixed
    {
        $cache = $this->source->fetchOne('SELECT cache FROM ' . $this->table('datacache') . " WHERE title = 'version'");
        if (!is_string($cache) || strlen($cache) > 1024) {
            return null;
        }
        $value = @unserialize($cache, ['allowed_classes' => false, 'max_depth' => 4]);
        return is_array($value) ? ($value['version'] ?? null) : null;
    }

    private function readonly(Closure $read): mixed
    {
        if ($this->source->isTransactionActive()) {
            throw new RuntimeException('import_source_transaction_active');
        }
        $this->source->executeStatement('SET TRANSACTION READ ONLY');
        $this->source->beginTransaction();
        try {
            return $read();
        } finally {
            $this->source->rollBack();
        }
    }

    private function table(string $name): string
    {
        return $this->source->quoteIdentifier($this->prefix . $name);
    }
}
