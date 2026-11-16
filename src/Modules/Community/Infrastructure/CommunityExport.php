<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use ZipArchive;

/** Complete bounded community supplement, private to the data subject; excess data fails explicitly. */
final class CommunityExport
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $storage,
    ) {
    }

    public function snapshot(int $user): array
    {
        if (!$this->db->createSchemaManager()->tablesExist(['member_profiles'])) {
            return [];
        }

        $imports = $this->db->createSchemaManager()->tablesExist(['import_private_files']);
        $result = [];
        $tables = [
            'member_profiles' => 'user_id, biography, location, signature, visibility, allow_messages, show_online, '
                . 'email_notifications, notify_replies, notify_reactions, notify_follows',
            'member_activity' => 'user_id, last_seen_at',
            'member_blocks' => 'user_id, target_id, created_at',
            'member_follows' => 'user_id, target_id, created_at',
            'member_bookmarks' => 'user_id, thread_id, created_at',
            'thread_subscriptions' => 'user_id, thread_id, email_optin, created_at',
            'post_reactions' => 'user_id, post_id, type_id, created_at',
            'thread_polls' => 'user_id, thread_id, question, multiple_choice, closes_at',
            'poll_ballots' => 'user_id, thread_id, created_at',
            'poll_votes' => 'user_id, thread_id, option_id',
            'thread_solutions' => 'user_id, thread_id, post_id, created_at',
            'member_notifications' => 'id, user_id, actor_id, kind, thread_id, post_id, read_at, created_at',
        ];

        foreach ($tables as $table => $columns) {
            if ($imports && $table === 'member_profiles') {
                $columns = str_replace('signature,', 'COALESCE(imported_signature, signature) AS signature,', $columns);
            }
            if ($imports && $table === 'thread_polls') {
                $columns = str_replace('question,', 'COALESCE(imported_question, question) AS question,', $columns);
            }
            $rows = $this->db->fetchAllAssociative('SELECT ' . $columns . ' FROM ' . $table
                . ' WHERE user_id = ? LIMIT 2001', [$user]);

            if (count($rows) > 2000) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $result[$table] = $rows;
        }

        $result['poll_options'] = $this->db->fetchAllAssociative('SELECT o.id, o.thread_id, '
            . ($imports ? 'COALESCE(o.imported_label, o.label)' : 'o.label') . ' AS label, o.position '
            . 'FROM poll_options o INNER JOIN thread_polls p ON p.thread_id = o.thread_id '
            . 'WHERE p.user_id = ? ORDER BY o.id LIMIT 2001', [$user]);

        if (count($result['poll_options']) > 2000) {
            throw new RegistrationRejected(['privacy_export_large']);
        }

        $result['media'] = $this->db->fetchAllAssociative('SELECT id, file_key, thumb_key, '
            . ($imports ? 'COALESCE(imported_name, original_name)' : 'original_name') . ' AS original_name, '
            . 'mime, bytes, width, height, post_id, avatar_for, created_at FROM community_media '
            . 'WHERE user_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 101', [$user]);

        if (count($result['media']) > 100) {
            throw new RegistrationRejected(['privacy_export_large']);
        }

        $result['private_files'] = $imports ? $this->db->fetchAllAssociative('SELECT f.id, f.conversation_id, '
            . 'f.file_key, f.original_name, f.bytes FROM import_private_files f INNER JOIN import_jobs j '
            . 'ON j.id = f.job_id AND j.state = ? WHERE f.owner_id = ? AND EXISTS '
            . '(SELECT 1 FROM private_conversation_members m WHERE m.conversation_id = f.conversation_id '
            . 'AND m.user_id = ?) ORDER BY f.id LIMIT 101', ['completed', $user, $user]) : [];
        if (count($result['private_files']) > 100) {
            throw new RegistrationRejected(['privacy_export_large']);
        }
        $result['files'] = [];
        foreach ($result['private_files'] as &$privateFile) {
            $path = $this->path($privateFile['file_key']);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || filesize($path) > 33554432) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
            $result['files'][] = ['key' => $privateFile['file_key'], 'sha256' => $hash];
            unset($privateFile['file_key']);
        }
        unset($privateFile);

        foreach ($result['media'] as &$media) {
            foreach (['file_key', 'thumb_key'] as $field) {
                $key = $media[$field];

                if ($key !== null) {
                    $path = $this->path($key);
                    $hash = hash_file('sha256', $path);

                    if (!is_string($hash) || filesize($path) > ($imports ? 33554432 : ImageProcessor::MAX_BYTES)) {
                        throw new RegistrationRejected(['privacy_export_failed']);
                    }

                    $result['files'][] = ['key' => $key, 'sha256' => $hash];
                }

                unset($media[$field]);
            }
        }

        unset($media);

        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 524288) {
            throw new RegistrationRejected(['privacy_export_large']);
        }

        return $result;
    }

    public function append(ZipArchive $zip, array $file): void
    {
        $path = $this->path($file['key'] ?? '');

        if (
            hash_file('sha256', $path) !== ($file['sha256'] ?? null)
            || !$zip->addFile($path, 'media/' . $file['key'])
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }
    }

    private function path(string $key): string
    {
        $root = realpath($this->storage);
        $directory = $root . '/community-media';
        $shard = $directory . '/' . substr($key, 0, 2);
        $path = $shard . '/' . $key;

        if (
            $root === false || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1
            || is_link($directory) || is_link($shard) || is_link($path) || !is_file($path)
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        return $path;
    }
}
