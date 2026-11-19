<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportRecords;
use Nolviko\Modules\Importers\Domain\ImportText;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\LegacyPassword;
use RuntimeException;

/** Target-only transactional mappings. No source roles, rendered HTML, email side effects or executable uploads. */
final class ImportWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly array $policy = [],
        private readonly ?ImportAuxWriter $auxiliary = null
    ) {
    }

    public function write(string $job, string $phase, array $row, string $engine): void
    {
        $id = (int) $row['id'];
        $sourceHash = self::sourceHash($row);
        $existing = $this->entry($job, $phase, $row['_key']);
        if ($existing !== false) {
            if (!hash_equals($existing['source_hash'], $sourceHash)) {
                throw new RuntimeException('import_source_changed');
            }
            return;
        }
        $now = gmdate('Y-m-d H:i:s') . '.000000';
        $slug = 'import-' . $job . '-' . $phase . '-' . $id;
        $meta = [];
        $parent = null;
        if (!isset(SourceSchema::SPECS[$engine][$phase])) {
            $result = ($this->auxiliary ?? throw new RuntimeException('import_auxiliary_missing'))
                ->write($job, $phase, $row, $engine);
            $target = $result['target'];
            $meta = $result['meta'];
            $parent = $result['parent'];
        } elseif ($phase === 'users' && $engine === 'phpbb' && ($id === 1 || (int) $row['active'] === 2)) {
            $target = 0;
            $meta['reason'] = 'system_identity';
        } elseif ($phase === 'groups') {
            $this->db->insert('usergroups', ['slug' => $slug, 'name' => self::text($row['name'], 100),
                'built_in' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $target = (int) $this->db->lastInsertId();
        } elseif ($phase === 'users' && isset($this->policy['user_map'][(string) $id])) {
            $target = (int) $this->policy['user_map'][(string) $id];
            $email = $this->db->fetchOne('SELECT email_normalized FROM users WHERE id = ?', [$target]);
            if (!is_string($email) || $email !== IdentityNormalizer::email((string) $row['email'])) {
                throw new RuntimeException('import_existing_user_map_denied');
            }
            $meta['reason'] = 'existing_owner_explicitly_linked';
        } elseif ($phase === 'users') {
            $name = self::text($row['name'], 64);
            $email = self::text($row['email'], 254);
            $normalizedName = IdentityNormalizer::username($name);
            $normalizedEmail = IdentityNormalizer::email($email);
            if (
                $name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($normalizedName, 'UTF-8') > 191
                || $this->db->fetchOne('SELECT id FROM users WHERE username_normalized = ? '
                    . 'OR email_normalized = ? LIMIT 1', [$normalizedName, $normalizedEmail]) !== false
            ) {
                throw new RuntimeException('import_identity_collision');
            }
            $active = match ($engine) {
                'smf' => (int) $row['active'] === 1,
                'mybb' => !in_array((int) $row['group'], [5, 7], true) && !(bool) ($row['source_banned'] ?? false),
                'phpbb' => in_array((int) $row['active'], [0, 3], true),
            };
            if (!LegacyPassword::supported($engine, (string) $row['hash'])) {
                $active = false;
                $meta['reason'] = 'password_reset_required';
            }
            try {
                $hash = LegacyPassword::encode($engine, (string) $row['hash'], match ($engine) {
                    'smf' => $name,
                    'mybb' => (string) $row['salt'],
                    'phpbb' => '',
                });
            } catch (InvalidArgumentException) {
                $hash = '!legacy-reset-required:' . bin2hex(random_bytes(32));
                $active = false;
                $meta['reason'] = 'password_reset_required';
            }
            $this->db->insert('users', ['username' => $name, 'username_normalized' => $normalizedName,
                'email' => $email, 'email_normalized' => $normalizedEmail, 'password_hash' => $hash,
                'status' => $active ? 'pending_approval' : 'suspended',
                'created_at' => ImportText::timestamp($row['time']), 'updated_at' => $now]);
            $target = (int) $this->db->lastInsertId();
            $this->db->insert('member_profiles', ['user_id' => $target, 'biography' => '', 'location' => '',
                'signature' => '', 'imported_signature' => ImportRecords::body($row['signature'] ?? ''),
                'visibility' => 'private', 'allow_messages' => 0, 'show_online' => 0,
                'email_notifications' => 0]);
            $this->db->insert('usergroup_members', ['user_id' => $target, 'usergroup_id' => 2,
                'is_primary' => 1, 'created_at' => $now]);
            foreach ($row['groups'] as $group) {
                $mapped = $this->mapped($job, 'groups', (int) $group);
                if ($mapped !== null) {
                    $this->db->insert('usergroup_members', ['user_id' => $target, 'usergroup_id' => $mapped,
                        'is_primary' => 0, 'created_at' => $now]);
                }
            }
        } elseif ($phase === 'forums' || $phase === 'categories') {
            $category = $phase === 'categories' || ($engine === 'mybb' && ($row['kind'] ?? '') === 'c')
                || ($engine === 'phpbb' && (int) ($row['kind'] ?? 1) === 0);
            $this->db->insert('forums', ['type' => $category ? 'category' : 'forum',
                'title' => self::text($row['name'], 200), 'description' => self::text($row['description'] ?? '', 10000),
                'slug' => $slug, 'sort_order' => (int) ($row['sort'] ?? 0),
                'created_at' => $now, 'updated_at' => $now]);
            $target = (int) $this->db->lastInsertId();
            $meta = ['parent' => (int) ($row['parent'] ?? 0), 'category' => (int) ($row['category'] ?? 0)];
            $groups = $this->db->fetchFirstColumn('SELECT id FROM usergroups ORDER BY id LIMIT 257');
            if (count($groups) > 256) {
                throw new RuntimeException('import_group_budget');
            }
            foreach ($groups as $group) {
                $this->db->insert('forum_permissions', ['forum_id' => $target, 'usergroup_id' => (int) $group,
                    'permission_key' => 'forum.view', 'value' => 'never']);
            }
            $this->db->insert('forum_statistics', ['forum_id' => $target, 'thread_count' => 0,
                'post_count' => 0, 'updated_at' => $now]);
        } elseif ($phase === 'threads') {
            if ($engine === 'phpbb' && (int) $row['forum'] === 0) {
                $this->globalForum($job);
                $forum = $this->required($job, 'forums', 0);
            } else {
                $forum = $this->required($job, 'forums', (int) $row['forum']);
            }
            $moved = (int) ($row['moved'] ?? 0);
            if ($engine === 'mybb' && str_starts_with((string) $row['locked'], 'moved|')) {
                $moved = (int) substr($row['locked'], 6);
            }
            $status = self::status($row['visible']);
            $this->db->insert('threads', ['forum_id' => $forum,
                'user_id' => $this->mapped($job, 'users', (int) $row['user']),
                'title' => self::text($row['name'], 255), 'slug' => $slug, 'status' => $status,
                'is_locked' => (int) ((int) $row['locked'] > 0), 'is_sticky' => (int) ((int) $row['sticky'] > 0),
                'created_at' => ImportText::timestamp($row['time']), 'updated_at' => $now]);
            $target = (int) $this->db->lastInsertId();
            $meta = ['first' => (int) $row['first'], 'moved' => $moved];
            if ($status === 'visible' && $moved === 0) {
                $this->db->executeStatement('UPDATE forum_statistics SET thread_count = thread_count + 1 '
                    . 'WHERE forum_id = ?', [$forum]);
            }
        } elseif ($phase === 'posts') {
            $thread = $this->entry($job, 'threads', (int) $row['thread']);
            if ($thread === false) {
                throw new RuntimeException('import_reference_missing');
            }
            $threadId = (int) $thread['target_id'];
            $threadMeta = json_decode($thread['meta_json'], true, 4, JSON_THROW_ON_ERROR);
            $first = (int) $threadMeta['first'] === $id;
            $status = self::status($row['visible']);
            $user = $this->mapped($job, 'users', (int) $row['user']);
            $created = ImportText::timestamp($row['time']);
            // Converted by the source (contract 1); inline attachments are still neutral markers here.
            $body = ImportRecords::body($row['body']);
            $this->db->insert('posts', ['thread_id' => $threadId, 'user_id' => $user, 'content' => $body,
                'content_format' => 'bbcode', 'status' => $status, 'created_at' => $created, 'updated_at' => $now]);
            $target = (int) $this->db->lastInsertId();
            if ($first) {
                $this->db->update('threads', ['first_post_id' => $target], ['id' => $threadId]);
            }
            if ($status === 'visible') {
                if (!$first) {
                    $this->db->executeStatement(
                        'UPDATE threads SET reply_count = reply_count + 1 WHERE id = ?',
                        [$threadId]
                    );
                }
                $this->db->executeStatement(
                    'UPDATE threads SET last_post_id = ?, last_post_at = ?, '
                    . 'last_poster_id = ? WHERE id = ? AND (last_post_at IS NULL OR last_post_at <= ?)',
                    [$target, $created, $user, $threadId, $created]
                );
                if ((int) $threadMeta['moved'] === 0) {
                    $this->db->executeStatement(
                        'UPDATE forum_statistics SET post_count = post_count + 1, '
                        . 'last_post_id = CASE WHEN last_post_at IS NULL OR last_post_at <= ? '
                        . 'THEN ? ELSE last_post_id END, '
                        . 'last_post_at = CASE WHEN last_post_at IS NULL OR last_post_at <= ? '
                        . 'THEN ? ELSE last_post_at END '
                        . 'WHERE forum_id = (SELECT t.forum_id FROM threads t WHERE t.id = ? AND t.status = ?)',
                        [$created, $target, $created, $created, $threadId, 'visible']
                    );
                }
            }
        } else {
            throw new RuntimeException('import_entity_invalid');
        }
        $this->db->insert('import_map', ['job_id' => $job, 'entity' => $phase, 'source_id' => $row['_key'],
            'target_id' => $target, 'source_hash' => $sourceHash,
            'content_hash' => (string) $target === '0' ? null : $this->targetHash($phase, $target),
            'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR), 'reason' => $meta['reason'] ?? null,
            'source_parent' => $parent]);
        if ((string) $target !== '0' && in_array($phase, ['users', 'forums', 'threads', 'posts'], true)) {
            $this->db->insert('legacy_redirects', ['job_id' => $job, 'entity' => $phase,
                'source_id' => $id, 'target_id' => $target]);
        }
    }

    private function globalForum(string $job): void
    {
        $key = ImportCursor::key([0]);
        if ($this->entry($job, 'forums', $key) !== false) {
            return;
        }
        $row = ['id' => 0, '_key' => $key, 'name' => 'phpBB', 'parent' => 0, 'kind' => 1,
            'description' => '', 'sort' => 0];
        $this->write($job, 'forums', $row, 'phpbb');
    }

    public function verify(string $job, string $phase, array $row): void
    {
        $entry = $this->entry($job, $phase, $row['_key']);
        if (
            $entry === false || !hash_equals($entry['source_hash'], self::sourceHash($row))
            || ((string) $entry['target_id'] !== '0'
                && !hash_equals($entry['content_hash'], $this->targetHash($phase, $entry['target_id'])))
        ) {
            throw new RuntimeException('import_parity_mismatch');
        }
        if ((string) $entry['target_id'] !== '0' && in_array($phase, ['attachments', 'private_files'], true)) {
            $this->auxiliary?->verifyAsset($row, json_decode($entry['meta_json'], true, 8, JSON_THROW_ON_ERROR));
        }
    }

    public function inline(string $job, array $source, ?InlineAttachments $rewriter): void
    {
        $entry = $this->entry($job, 'posts', $source['_key'])
            ?: throw new RuntimeException('import_reference_missing');
        if (!hash_equals($entry['content_hash'], $this->targetHash('posts', $entry['target_id']))) {
            throw new RuntimeException('import_parity_mismatch');
        }
        $body = ImportRecords::body($source['body']);
        if ($rewriter !== null) {
            $body = ImportRecords::body($rewriter->rewrite($job, $source['id'], $body));
        } elseif (InlineAttachments::has($body)) {
            throw new RuntimeException('import_canonical_url_required');
        }
        $this->db->update('posts', ['content' => $body], ['id' => (int) $entry['target_id']]);
        $this->db->update(
            'import_map',
            ['content_hash' => $this->targetHash('posts', $entry['target_id'])],
            ['job_id' => $job, 'entity' => 'posts', 'source_id' => $source['_key']]
        );
    }

    public function reparent(string $job, int $source): void
    {
        $entry = $this->entry($job, 'forums', $source) ?: throw new RuntimeException('import_reference_missing');
        $meta = json_decode($entry['meta_json'], true, 4, JSON_THROW_ON_ERROR);
        $parent = null;
        $depth = 0;
        $seen = [$source];
        while ((int) $meta['parent'] > 0) {
            $ancestor = (int) $meta['parent'];
            if (in_array($ancestor, $seen, true) || ++$depth > 8) {
                throw new RuntimeException('import_forum_hierarchy_invalid');
            }
            $seen[] = $ancestor;
            $node = $this->entry($job, 'forums', $ancestor)
                ?: throw new RuntimeException('import_reference_missing');
            $parent ??= (int) $node['target_id'];
            $meta = json_decode($node['meta_json'], true, 4, JSON_THROW_ON_ERROR);
        }
        if ((int) $meta['category'] > 0) {
            ++$depth;
            $parent ??= $this->required($job, 'categories', (int) $meta['category']);
        }
        if ($depth > 8) {
            throw new RuntimeException('import_forum_hierarchy_invalid');
        }
        $this->db->update('forums', ['parent_id' => $parent, 'depth' => $depth], ['id' => (int) $entry['target_id']]);
    }

    public function moved(string $job, int $source): void
    {
        $entry = $this->entry($job, 'threads', $source) ?: throw new RuntimeException('import_reference_missing');
        $meta = json_decode($entry['meta_json'], true, 4, JSON_THROW_ON_ERROR);
        if ((int) $meta['moved'] > 0) {
            $seen = [$source];
            $next = (int) $meta['moved'];
            for ($depth = 0;; ++$depth) {
                if ($depth >= 32 || in_array($next, $seen, true)) {
                    throw new RuntimeException('import_redirect_cycle');
                }
                $seen[] = $next;
                $node = $this->entry($job, 'threads', $next)
                    ?: throw new RuntimeException('import_reference_missing');
                $nextMeta = json_decode($node['meta_json'], true, 4, JSON_THROW_ON_ERROR);
                $next = (int) $nextMeta['moved'];
                if ($next === 0) {
                    $target = (int) $node['target_id'];
                    break;
                }
            }
            $this->db->update('threads', ['moved_to_thread_id' => $target], ['id' => (int) $entry['target_id']]);
        }
        if ((int) $meta['first'] > 0) {
            $this->required($job, 'posts', (int) $meta['first']);
        }
    }

    private function targetHash(string $phase, int|string $id): string
    {
        if (!in_array($phase, ['categories', 'groups', 'users', 'forums', 'threads', 'posts'], true)) {
            return ($this->auxiliary ?? throw new RuntimeException('import_auxiliary_missing'))->hash(
                $phase,
                (string) $id,
            );
        }
        [$table, $columns] = match ($phase) {
            'categories', 'forums' => ['forums', 'title, description, slug, type'],
            'groups' => ['usergroups', 'name, slug, built_in'],
            'users' => ['users', 'username, username_normalized, email, email_normalized, password_hash, status'],
            'threads' => ['threads', 'forum_id, user_id, title, slug, status, is_locked, is_sticky'],
            'posts' => ['posts', 'thread_id, user_id, content, content_format, status, created_at'],
            default => throw new RuntimeException('import_entity_invalid'),
        };
        $row = $this->db->fetchAssociative('SELECT ' . $columns . ' FROM ' . $table . ' WHERE id = ?', [$id]);
        if ($row === false) {
            throw new RuntimeException('import_target_missing');
        }
        if ($phase === 'users') {
            $row['profile'] = $this->db->fetchAssociative(
                'SELECT biography, location, signature, imported_signature, '
                . 'visibility, allow_messages, show_online, email_notifications FROM member_profiles WHERE user_id = ?',
                [$id]
            );
        }
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    private function entry(string $job, string $phase, int|string $source): array|false
    {
        return $this->db->fetchAssociative('SELECT target_id, source_hash, content_hash, meta_json '
            . 'FROM import_map WHERE job_id = ? AND entity = ? AND source_id = '
                . '?', [
                $job,
                $phase,
                is_int($source) ? ImportCursor::key([$source]) : $source,
            ]);
    }

    private function required(string $job, string $phase, int $source): int
    {
        return $this->mapped($job, $phase, $source) ?? throw new RuntimeException('import_reference_missing');
    }

    private function mapped(string $job, string $phase, int $source): ?int
    {
        if ($source < 0 || ($source === 0 && $phase !== 'forums')) {
            return null;
        }
        $entry = $this->entry($job, $phase, $source);
        return $entry === false || (int) $entry['target_id'] === 0 ? null : (int) $entry['target_id'];
    }

    private static function status(mixed $source): string
    {
        return match ((int) $source) {
            1 => 'visible',
            0 => 'pending',
            default => 'hidden',
        };
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (
            !is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || mb_strlen($value, 'UTF-8') > $maximum
        ) {
            throw new RuntimeException('import_text_invalid');
        }
        return $value;
    }

    /** The source's fingerprint of the raw row (contract 1); rows the target makes itself are hashed here. */
    private static function sourceHash(array $row): string
    {
        return is_string($row['_hash'] ?? null)
            ? $row['_hash'] : hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
