<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Community\Infrastructure\CommunityExport;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;
use ZipArchive;

/** Bounded complete ZIP of the selected member's stored profile and authored content; never silent truncation. */
final class PersonalDataExport
{
    private const MAX_ROWS = 2000;

    private const SOURCE_BYTES = 8388608;

    private const JSON_BYTES = 16777216;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $storage,
        private readonly string $public,
    ) {
    }

    public function resumable(): ResumableDataExport
    {
        return new ResumableDataExport($this->connection, $this->storage, $this->public);
    }

    /** Caller holds the user's row lock and a repeatable-read transaction after authorization. */
    public function create(int $userId, bool $includeConversations = true): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $directory = $this->directory();
        $lockFile = $directory . '/export.lock';

        if (is_link($lockFile)) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $lock = fopen($lockFile, 'c+b');

        if ($lock === false) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        chmod($lockFile, 0600);
        $path = null;

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RegistrationRejected(['privacy_export_busy']);
            }

            $files = glob($directory . '/data-*') ?: [];
            $bytes = 0;
            $retained = 0;

            foreach ($files as $file) {
                if (is_link($file) || !is_file($file)) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }

                if (filemtime($file) < time() - 3600 && unlink($file)) {
                    continue;
                }

                $bytes += filesize($file);
                ++$retained;
            }

            if ($retained >= 32 || $bytes > 50331648) {
                throw new RegistrationRejected(['privacy_export_busy']);
            }

            $profile = $this->connection->fetchAssociative(
                'SELECT id, username, email, status, preferred_locale, time_zone, created_at, updated_at '
                    . 'FROM users WHERE id = ?',
                [$userId],
            );

            if ($profile === false) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            $path = tempnam($directory, 'data-');

            if ($path === false || realpath(dirname($path)) !== realpath($directory) || !chmod($path, 0600)) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            $community = $includeConversations
                ? (new CommunityExport($this->connection, $this->storage))->snapshot($userId) : [];
            $profile['community'] = $community;
            $parts = [];

            try {
                $metadata = json_encode([
                    'format' => 1,
                    'user_id' => $userId,
                    'content_policy' => 'retained_with_null_author_after_account_deletion',
                    'not_implemented' => [],
                    'restricted_sections' => $includeConversations ? [] : ['conversations', 'messages'],
                    'private_security_records_included' => false,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                foreach (
                    [
                    'profile.json' => json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                    'export.json' => $metadata,
                    ] as $name => $content
                ) {
                    if (!$zip->addFromString($name, $content)) {
                        throw new RegistrationRejected(['privacy_export_failed']);
                    }
                }
                foreach ($community['files'] ?? [] as $file) {
                    (new CommunityExport($this->connection, $this->storage))->append($zip, $file);
                }

                $sections = [
                    'posts.jsonl' => fn(): string => $this->rows('posts', $userId),
                    'threads.jsonl' => fn(): string => $this->rows('threads', $userId),
                ];

                if ($includeConversations) {
                    $sections['conversations.jsonl'] = fn(): string => $this->privateRows($userId, false);
                    $sections['messages.jsonl'] = fn(): string => $this->privateRows($userId, true);
                }

                foreach ($sections as $name => $read) {
                    $part = tempnam($directory, 'data-part-');

                    if ($part === false || realpath(dirname($part)) !== realpath($directory) || !chmod($part, 0600)) {
                        throw new RegistrationRejected(['privacy_export_failed']);
                    }

                    $parts[] = $part;
                    $content = $read();

                    if (
                        file_put_contents($part, $content, LOCK_EX) !== strlen($content)
                        || !$zip->addFile($part, $name)
                    ) {
                        throw new RegistrationRejected(['privacy_export_failed']);
                    }

                    unset($content);
                }
            } finally {
                $closed = $zip->close();

                foreach ($parts as $part) {
                    @unlink($part);
                }

                if (!$closed) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }
            }

            return $path;
        } catch (Throwable $failure) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw $failure;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rows(string $kind, int $userId): string
    {
        // Only these two constant-owned query shapes exist; no browser identifiers reach SQL.
        $posts = $kind === 'posts';
        $table = $posts ? 'posts' : 'threads';
        $text = $posts ? 'content' : 'title';
        $size = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(SUM(OCTET_LENGTH(' . $text . ')), 0) AS bytes, '
                . 'COALESCE(MAX(OCTET_LENGTH(' . $text . ')), 0) AS largest FROM ' . $table . ' WHERE user_id = ?',
            [$userId],
        );

        if (
            (int) $size['total'] > self::MAX_ROWS || (int) $size['bytes'] > self::SOURCE_BYTES
            || (int) $size['largest'] > 262144
        ) {
            throw new RegistrationRejected(['privacy_export_large']);
        }

        $columns = $posts ? 'id, thread_id, content, content_format, status, created_at, updated_at'
            : 'id, forum_id, title, slug, status, created_at, updated_at';
        if ($imports) {
            $columns = str_replace(
                $messages ? 'data.content' : 'data.subject',
                $text . ($messages ? ' AS content' : ' AS subject'),
                $columns
            );
        }
        $rows = $this->connection->iterateAssociative(
            'SELECT ' . $columns . ' FROM ' . $table . ' WHERE user_id = ? ORDER BY id LIMIT 2001',
            [$userId],
        );
        $json = '';
        $count = 0;

        foreach ($rows as $row) {
            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

            if (++$count > self::MAX_ROWS || strlen($json) + strlen($line) > self::JSON_BYTES) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $json .= $line;
        }

        return $json;
    }

    /** Received and authored messages are exported only through the selected account's own membership. */
    private function privateRows(int $userId, bool $messages): string
    {
        $table = $messages ? 'private_messages data' : 'private_conversations data';
        $conversation = $messages ? 'data.conversation_id' : 'data.id';
        $text = $messages ? 'data.content' : 'data.subject';
        $imports = $this->connection->createSchemaManager()->tablesExist(['import_private_files']);
        if ($imports) {
            $text = $messages ? 'COALESCE(data.imported_content, data.content)'
                : 'COALESCE(data.imported_subject, data.subject)';
        }
        $scope = 'EXISTS (SELECT 1 FROM private_conversation_members member WHERE member.conversation_id = '
            . $conversation . ' AND member.user_id = ?)';
        $size = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(SUM(OCTET_LENGTH(' . $text . ')), 0) AS bytes, '
                . 'COALESCE(MAX(OCTET_LENGTH(' . $text . ')), 0) AS largest FROM ' . $table . ' WHERE ' . $scope,
            [$userId],
        );

        if (
            (int) $size['total'] > self::MAX_ROWS || (int) $size['bytes'] > self::SOURCE_BYTES
            || (int) $size['largest'] > 262144
        ) {
            throw new RegistrationRejected(['privacy_export_large']);
        }

        $columns = $messages ? 'data.id, data.conversation_id, data.user_id, data.content, data.created_at'
            : 'data.id, data.subject, data.created_at, data.updated_at';
        $rows = $this->connection->iterateAssociative(
            'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $scope . ' ORDER BY data.id LIMIT 2001',
            [$userId],
        );
        $json = '';
        $count = 0;

        foreach ($rows as $row) {
            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

            if (++$count > self::MAX_ROWS || strlen($json) + strlen($line) > self::JSON_BYTES) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $json .= $line;
        }

        return $json;
    }

    private function directory(): string
    {
        $storage = realpath($this->storage);
        $public = realpath($this->public);
        $key = static fn(string $path): string => strtolower(str_replace('\\', '/', $path));

        if (
            $storage === false || $public === false || is_link($this->storage)
            || str_starts_with($key($storage) . '/', $key($public) . '/')
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $directory = $storage . '/privacy';

        if (
            is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700))
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($directory) & 0077) !== 0)
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        return $directory;
    }
}
