<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Importers\Domain\ImportAsset;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportText;
use RuntimeException;

/** Private per-owner archives and native poll/media projections, all under the caller's import transaction. */
final readonly class ImportAuxWriter
{
    /** @param Closure(array): ?ImportAsset $asset the original file of an attachment record (ADR-038) */
    public function __construct(private Connection $db, private Closure $asset, private ImportFileStore $files)
    {
    }

    public function write(string $job, string $phase, array $row, string $engine): array
    {
        $meta = [];
        $parent = null;
        if (str_starts_with($phase, 'private_') && $phase !== 'private_files') {
            $owner = $this->mapped($job, 'users', (int) $row['owner']);
            if ($owner === null) {
                return ['target' => '0', 'meta' => ['reason' => 'recipient_deleted'], 'parent' => (int) $row['id']];
            }
            $subject = self::text((string) $row['name'], 255);
            $body = ImportText::body((string) $row['body'], $engine, 'UTF-8');
            $created = ImportText::timestamp($row['time']);
            $this->db->insert('private_conversations', ['subject' => mb_substr($subject ?: (string) $row['id'], 0, 160),
                'imported_subject' => $subject === '' ? null : $subject, 'import_job_id' => $job,
                'created_at' => $created, 'updated_at' => $created]);
            $target = (string) $this->db->lastInsertId();
            $this->db->insert('private_conversation_members', ['conversation_id' => (int) $target,
                'user_id' => $owner]);
            $this->db->insert('private_messages', ['conversation_id' => (int) $target,
                'user_id' => $this->mapped($job, 'users', (int) $row['author']),
                'content' => mb_substr($body, 0, 8000), 'imported_content' => $body, 'created_at' => $created]);
            $meta = ['kind' => 'owner_archive', 'owner' => (int) $row['owner']];
            $parent = (int) $row['id'];
        } elseif ($phase === 'attachments' || $phase === 'private_files') {
            $private = $phase === 'private_files';
            $post = $private ? null : $this->mapped($job, 'posts', (int) $row['post']);
            $owner = $private ? $this->mapped($job, 'users', (int) $row['owner']) : null;
            $conversation = $private ? $this->mapped(
                $job,
                'private_inbox',
                ImportCursor::key([(int) $row['post'], (int) $row['owner']])
            ) : null;
            if (!$private && $post === null) {
                $owner = $this->mapped($job, 'users', (int) $row['user']);
                if ($owner !== null) {
                    $created = ImportText::timestamp($row['time']);
                    $this->db->insert('private_conversations', ['subject' => (string) $row['id'],
                        'imported_subject' => (string) $row['name'], 'import_job_id' => $job,
                        'created_at' => $created, 'updated_at' => $created]);
                    $conversation = (int) $this->db->lastInsertId();
                    $this->db->insert('private_conversation_members', ['conversation_id' => $conversation,
                        'user_id' => $owner]);
                    $this->db->insert('private_messages', ['conversation_id' => $conversation,
                        'user_id' => $owner, 'content' => (string) $row['id'], 'created_at' => $created]);
                    $private = true;
                }
            }
            if ((!$private && $post === null) || ($private && ($owner === null || $conversation === null))) {
                return ['target' => '0', 'meta' => ['reason' => 'unowned_source_attachment'],
                    'parent' => (int) $row['post']];
            }
            $asset = ($this->asset)($row) ?? throw new RuntimeException('import_attachment_missing');
            $stored = $this->files->retain($job, (int) $row['id'], $asset);
            $name = self::text((string) $row['name'], 255);
            $safeName = preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $name) ?: (string) $row['id'];
            $target = substr(hash('sha256', 'import-media:' . $job . ':' . $row['_key'] . ':' . $phase), 0, 32);
            if ($private) {
                $this->db->insert('import_private_files', ['id' => $target, 'job_id' => $job,
                    'source_id' => (int) $row['id'], 'owner_id' => $owner, 'conversation_id' => $conversation,
                    'file_key' => $stored['key'], 'original_name' => $safeName, 'bytes' => $stored['bytes'],
                    'source_hash' => $stored['sha256']]);
            } else {
                $user = $this->mapped($job, 'users', (int) $row['user']);
                $this->db->insert('community_media', ['id' => $target, 'user_id' => $user, 'post_id' => $post,
                    'file_key' => $stored['key'], 'original_name' => mb_substr($safeName, 0, 160),
                    'imported_name' => $safeName, 'imported_retained' => $user === null ? 1 : 0,
                    'imported_visibility' => (int) $row['visible'] === 1 ? 'visible' : 'pending',
                    'mime' => 'application/octet-stream', 'bytes' => $stored['bytes'],
                    'created_at' => ImportText::timestamp($row['time'])]);
                $this->db->insert('legacy_redirects', ['job_id' => $job, 'entity' => 'attachments',
                    'source_id' => (int) $row['id'], 'target_id' => $target]);
            }
            $meta = ['file_hash' => $stored['sha256'], 'source_name' => $name, 'post' => (int) $row['post'],
                'file_key' => $stored['key'], 'reason' => $private && $phase === 'attachments'
                    ? 'orphan_file_owner_archive' : 'original_file_retained'];
            $parent = (int) $row['post'];
        } elseif ($phase === 'polls') {
            $target = (string) $this->required($job, 'threads', (int) $row['thread']);
            $question = self::text((string) $row['name'], 255);
            $choices = (int) $row['choices'];
            if ($question === '' || $choices < 1 || $choices > 100 || (int) $row['closes'] < 0) {
                throw new RuntimeException('import_poll_source_invalid');
            }
            $this->db->insert('thread_polls', ['thread_id' => (int) $target,
                'user_id' => $this->mapped($job, 'users', (int) $row['user']),
                'question' => mb_substr($question, 0, 240), 'imported_question' => $question,
                'multiple_choice' => $choices > 1 ? 1 : 0, 'imported_max_choices' => $choices,
                    'imported_lock' => (int) $row['locked'] > 0 ? 1
                    : ($choices > 5 ? 2 : 0), 'imported_results' => min(2, max(0, (int) $row['results'])),
                'closes_at' => (int) $row['closes'] === 0 ? null : ImportText::timestamp($row['closes'])]);
            $meta = ['choices' => $choices];
        } elseif ($phase === 'poll_options') {
            $thread = $this->required($job, 'polls', (int) $row['poll']);
            $label = self::text((string) $row['label'], 255);
            if ((int) $row['votes'] < 0) {
                throw new RuntimeException('import_poll_source_invalid');
            }
            $this->db->insert('poll_options', ['thread_id' => $thread, 'label' => mb_substr($label, 0, 160),
                'imported_label' => $label, 'position' => (int) $row['option'], 'imported_votes'
                    => (int) $row['votes']]);
            $target = (string) $this->db->lastInsertId();
        } elseif ($phase === 'poll_votes') {
            $user = $this->mapped($job, 'users', (int) $row['user']);
            if ($user === null) {
                return ['target' => '0', 'meta' => ['reason' => 'unattributed_vote_total'], 'parent' => null];
            }
            $thread = $this->required($job, 'polls', (int) $row['poll']);
            $option = $this->required(
                $job,
                'poll_options',
                ImportCursor::key([(int) $row['poll'], (int) $row['option']])
            );
            $existing = $this->db->fetchOne('SELECT 1 FROM poll_votes WHERE user_id = ? AND thread_id = ? '
                . 'AND option_id = ?', [$user, $thread, $option]);
            if ($existing !== false) {
                return ['target' => '0', 'meta' => ['reason' => 'duplicate_vote_in_aggregate'], 'parent' => null];
            }
            if (
                $this->db->fetchOne(
                    'SELECT 1 FROM poll_ballots WHERE user_id = ? AND thread_id = ?',
                    [$user, $thread]
                ) === false
            ) {
                $this->db->insert('poll_ballots', ['user_id' => $user, 'thread_id' => $thread,
                    'created_at' => gmdate('Y-m-d H:i:s') . '.000000']);
            }
            $this->db->insert('poll_votes', ['user_id' => $user, 'thread_id' => $thread, 'option_id' => $option]);
            if (
                $this->db->executeStatement('UPDATE poll_options SET imported_votes = imported_votes - 1 '
                . 'WHERE id = ? AND thread_id = ? AND imported_votes > 0', [$option, $thread]) !== 1
            ) {
                throw new RuntimeException('import_poll_count_mismatch');
            }
            $target = ImportCursor::key([$thread, $user, $option]);
        } else {
            throw new RuntimeException('import_entity_invalid');
        }
        return ['target' => $target, 'meta' => $meta, 'parent' => $parent];
    }

    public function hash(string $phase, string $target): string
    {
        if (str_starts_with($phase, 'private_') && $phase !== 'private_files') {
            $row = $this->db->fetchAssociative(
                'SELECT c.subject, c.imported_subject, c.import_job_id, '
                . 'm.user_id, COALESCE(m.imported_content, m.content) AS content, m.created_at FROM '
                . 'private_conversations c INNER JOIN private_messages m ON m.conversation_id = c.id WHERE c.id = ?',
                [(int) $target]
            );
            if ($row !== false) {
                $row['members'] = $this->db->fetchFirstColumn('SELECT user_id FROM private_conversation_members '
                    . 'WHERE conversation_id = ? ORDER BY user_id', [(int) $target]);
            }
        } elseif ($phase === 'attachments' || $phase === 'private_files') {
            $row = $phase === 'private_files'
                ? $this->db->fetchAssociative('SELECT job_id, source_id, owner_id, conversation_id, file_key, '
                    . 'original_name, bytes, source_hash FROM import_private_files WHERE id = ?', [$target])
                : $this->db->fetchAssociative(
                    'SELECT user_id, post_id, file_key, original_name, imported_name, '
                    . 'imported_retained, imported_visibility, mime, bytes, deleted_at '
                        . 'FROM community_media WHERE id = ?',
                    [$target]
                );
            if ($row === false && $phase === 'attachments') {
                $row = $this->db->fetchAssociative('SELECT job_id, source_id, owner_id, conversation_id, file_key, '
                    . 'original_name, bytes, source_hash FROM import_private_files WHERE id = ?', [$target]);
            }
            if ($row !== false) {
                $row['actual_hash'] = hash_file('sha256', $this->files->path($row['file_key']));
            }
        } elseif ($phase === 'polls') {
            $row = $this->db->fetchAssociative(
                'SELECT thread_id, user_id, question, imported_question, '
                . 'multiple_choice, closes_at, imported_lock, imported_results FROM thread_polls WHERE thread_id = ?',
                [(int) $target]
            );
        } elseif ($phase === 'poll_options') {
            $row = $this->db->fetchAssociative('SELECT o.thread_id, o.label, o.imported_label, o.position, '
                . 'o.imported_votes + (SELECT COUNT(*) FROM poll_votes v WHERE v.thread_id = o.thread_id '
                . 'AND v.option_id = o.id) AS total FROM poll_options o WHERE o.id = ?', [(int) $target]);
        } elseif ($phase === 'poll_votes') {
            [$thread, $user, $option] = ImportCursor::parts($target, 3);
            $row = $this->db->fetchAssociative('SELECT user_id, thread_id, option_id FROM poll_votes WHERE '
                . 'thread_id = ? AND user_id = ? AND option_id = ?', [$thread, $user, $option]);
        } else {
            throw new RuntimeException('import_entity_invalid');
        }
        if ($row === false) {
            throw new RuntimeException('import_target_missing');
        }
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function verifyAsset(array $row, array $meta): void
    {
        $asset = ($this->asset)($row) ?? throw new RuntimeException('import_attachment_missing');
        if (!hash_equals($meta['file_hash'] ?? '', $asset->digest())) {
            throw new RuntimeException('import_attachment_changed');
        }
    }

    private function mapped(string $job, string $phase, int|string $source): ?int
    {
        if (is_int($source) && $source <= 0) {
            return null;
        }
        $id = $this->db->fetchOne('SELECT target_id FROM import_map WHERE job_id = ? AND entity = ? '
            . 'AND source_id = ?', [$job, $phase, is_int($source) ? ImportCursor::key([$source]) : $source]);
        return $id === false || $id === '0' ? null : (int) $id;
    }

    private function required(string $job, string $phase, int|string $source): int
    {
        return $this->mapped($job, $phase, $source) ?? throw new RuntimeException('import_reference_missing');
    }

    private static function text(string $value, int $limit): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || mb_strlen($value, 'UTF-8') > $limit) {
            throw new RuntimeException('import_text_invalid');
        }
        return $value;
    }
}
