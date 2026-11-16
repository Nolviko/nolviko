<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Community\Infrastructure\CommunityExport;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;
use ZipArchive;

/** Private actor/subject-bound journal, immutable JSONL parts and a complete-only ZIP; no browser job paths. */
final class ResumableDataExport
{
    private const KINDS = ['posts', 'threads', 'conversations', 'messages'];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $storage,
        private readonly string $public,
    ) {
    }

    /** Called only after current authorization/password and while holding the subject's database row lock. */
    public function request(int $actor, int $target, bool $private): ?string
    {
        if (!$this->connection->isTransactionActive() || ($private && $actor !== $target)) {
            throw new RegistrationRejected(['privacy_denied']);
        }

        $root = $this->root();
        $pointer = $root . '/pair-' . hash('sha256', $actor . ':' . $target . ':' . (int) $private) . '.json';
        $known = is_file($pointer) ? $this->read($pointer) : [];
        $job = $known['job'] ?? '';
        $state = $this->state($job);

        if (
            $state === null || $state['expires'] < time() || $state['status'] === 'failed'
            || ($state['status'] === 'ready' && !is_file($root . '/job-' . $job . '/data.zip'))
        ) {
            $this->cleanup($root);

            if (count(glob($root . '/job-*') ?: []) >= 32) {
                throw new RegistrationRejected(['privacy_export_busy']);
            }

            $job = bin2hex(random_bytes(16));
            $directory = $root . '/job-' . $job;

            if (!mkdir($directory, 0700) || !mkdir($directory . '/parts', 0700)) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            $profile = $this->connection->fetchAssociative(
                'SELECT id, username, email, status, preferred_locale, time_zone, '
                    . 'theme_preference, created_at, updated_at '
                    . 'FROM users WHERE id = ?',
                [$target],
            );

            if ($profile === false) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            if ($private) {
                $saved = $this->connection->fetchAllAssociative(
                    'SELECT name, context, filters_json, created_at, updated_at FROM admin_saved_filters '
                        . 'WHERE user_id = ? ORDER BY id LIMIT 31',
                    [$target],
                );
                if (count($saved) > 30) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }
                $profile['saved_admin_filters'] = $saved;
            }

            $profile['community'] = $private
                ? (new CommunityExport($this->connection, $this->storage))->snapshot($target) : [];
            $bounds = [];

            foreach (['posts', 'threads', 'private_conversations', 'private_messages'] as $table) {
                $bounds[] = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ' . $table);
            }

            $state = ['job' => $job, 'actor' => $actor, 'target' => $target, 'private' => $private,
                'profile' => $profile, 'bounds' => $bounds, 'stage' => 0, 'cursor' => 0,
                'conversation' => 0, 'last_conversation' => 0, 'parts' => [], 'bytes' => 0, 'rows' => 0,
                'status' => 'pending', 'started' => time(), 'expires' => time() + 604800];
            $this->save($directory . '/state.json', $state);
            $this->save($pointer, ['job' => $job]);
        }

        if ($state['actor'] !== $actor || $state['target'] !== $target || $state['private'] !== $private) {
            throw new RegistrationRejected(['privacy_denied']);
        }

        if ($state['status'] === 'ready') {
            $path = $root . '/job-' . $job . '/data.zip';

            if (is_link($path) || hash_file('sha256', $path) !== $state['zip_hash']) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            return $path;
        }

        $key = 'account.export:' . $job;
        $status = $this->connection->fetchOne('SELECT status FROM outbox_events WHERE dedupe_key = ?', [$key]);

        if ($status === false) {
            (new Outbox($this->connection))->record('user.export_requested', 'user', $target, ['job' => $job], $key);
        } elseif ($status === 'failed') {
            $this->connection->executeStatement(
                "UPDATE outbox_events SET status = 'pending', attempts = 0, owner_token = NULL, "
                    . 'lease_expires_at = NULL, available_at = CURRENT_TIMESTAMP(6), revision = revision + 1 '
                    . "WHERE dedupe_key = ? AND status = 'failed'",
                [$key],
            );
        }

        return null;
    }

    /** Read-only safe projection; no job ID, filenames, content, email or credentials. */
    public function progress(int $actor, int $target, bool $private): ?array
    {
        $pointer = $this->root() . '/pair-' . hash('sha256', $actor . ':' . $target . ':' . (int) $private) . '.json';
        $state = is_file($pointer) ? $this->state($this->read($pointer)['job'] ?? '') : null;

        if (
            $state === null || $state['actor'] !== $actor
            || $state['target'] !== $target || $state['expires'] < time()
        ) {
            return null;
        }

        $delivery = $this->connection->fetchOne(
            'SELECT status FROM outbox_events WHERE dedupe_key = ?',
            ['account.export:' . $state['job']]
        );

        return ['status' => $delivery === 'failed' ? 'failed' : $state['status'], 'rows' => $state['rows']];
    }

    /** Registered worker only; source reads and file checkpoint are idempotent across queue transaction retries. */
    public function step(int $target, string $job, Closure $guard): bool
    {
        $guard();
        $state = $this->state($job);

        if (
            $state === null || $state['target'] !== $target
            || $state['expires'] < time() || $state['status'] !== 'pending'
        ) {
            return true;
        }

        $user = $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$target]);
        $processing = $this->connection->fetchOne(
            'SELECT processing_started_at FROM account_deletions WHERE user_id = ?',
            [$target],
        );

        if ($user === false || is_string($processing)) {
            $state['status'] = 'failed';
            $this->save($this->root() . '/job-' . $job . '/state.json', $state);

            return true;
        }

        $directory = $this->root() . '/job-' . $job;
        if (is_link($directory . '/lock')) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $lock = fopen($directory . '/lock', 'c+b');

        if ($lock === false) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        try {
            chmod($directory . '/lock', 0600);

            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RegistrationRejected(['privacy_export_busy']);
            }

            // Reload under the file lock; another reader may have observed an earlier atomic checkpoint.
            $state = $this->state($job);

            if ($state === null || $state['status'] !== 'pending') {
                return true;
            }

            if (!$state['private'] && $state['stage'] === 2) {
                $state['stage'] = 4;
            }

            if ($state['stage'] >= 4) {
                return $this->finish($directory, $state, $guard);
            }

            $index = count($state['parts']);

            if ($index >= 10000) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $name = self::KINDS[$state['stage']] . '-' . $index . '.jsonl';
            $path = $directory . '/parts/' . $name;

            if (is_file($path)) {
                $part = $this->readPart($path, $job);
            } else {
                $part = $this->collect($state);
                $guard();
                $this->storageBudget(strlen($part['body']));
                $this->write($path, json_encode($part['header'], JSON_THROW_ON_ERROR) . "\n" . $part['body']);
            }

            if ($state['bytes'] + strlen($part['body']) > 67108864) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $state['parts'][] = ['name' => $name, 'hash' => $part['header']['hash']];
            $state['bytes'] += strlen($part['body']);
            $state['rows'] += $part['header']['rows'];

            foreach (['stage', 'cursor', 'conversation', 'last_conversation'] as $field) {
                $state[$field] = $part['header'][$field];
            }

            $guard();
            $this->save($directory . '/state.json', $state);

            return false;
        } catch (RegistrationRejected $failure) {
            if (in_array($failure->diagnostics[0], ['privacy_export_large', 'privacy_export_failed'], true)) {
                $state['status'] = 'failed';
                $this->save($directory . '/state.json', $state);
            }

            throw $failure;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function collect(array $state): array
    {
        $after = $state;
        $stage = $state['stage'];
        $target = $state['target'];
        $cursor = $state['cursor'];
        $limit = $state['bounds'][$stage];
        $parameters = [$target, $cursor, $limit];

        if ($stage === 0 || $stage === 1) {
            $table = $stage === 0 ? 'posts' : 'threads';
            $text = $stage === 0 ? 'content' : 'title';
            $columns = $stage === 0 ? 'id, thread_id, user_id, content_format, status, created_at, updated_at'
                : 'id, forum_id, user_id, slug, status, created_at, updated_at';
            $sql = 'SELECT ' . $columns . ', CASE WHEN OCTET_LENGTH(' . $text . ') <= 262144 THEN ' . $text
                . ' ELSE NULL END AS ' . $text . ' FROM ' . $table . ' WHERE user_id = ? AND id > ? AND id <= ?';
        } elseif ($stage === 2) {
            $text = 'subject';
            $sql = 'SELECT c.id, c.subject, c.created_at, c.updated_at FROM private_conversations c '
                . 'INNER JOIN private_conversation_members member ON member.conversation_id = c.id '
                . 'WHERE member.user_id = ? AND c.id > ? AND c.id <= ?';
        } else {
            $text = 'content';

            if ($state['conversation'] === 0) {
                $conversation = $this->connection->fetchOne(
                    'SELECT conversation_id FROM private_conversation_members WHERE user_id = ? '
                        . 'AND conversation_id > ? AND conversation_id <= ? ORDER BY conversation_id LIMIT 1',
                    [$target, $state['last_conversation'], $state['bounds'][2]],
                );

                if ($conversation === false) {
                    $after['stage'] = 4;

                    return $this->part($state['job'], $after, '', 0);
                }

                $after['conversation'] = (int) $conversation;
            }

            $sql = 'SELECT message.id, message.conversation_id, message.user_id, message.created_at, '
                . 'CASE WHEN OCTET_LENGTH(message.content) <= 262144 THEN message.content ELSE NULL END AS content '
                . 'FROM private_messages message WHERE message.conversation_id = ? AND message.id > ? '
                . 'AND message.id <= ? AND EXISTS (SELECT 1 FROM private_conversation_members member '
                . 'WHERE member.conversation_id = message.conversation_id AND member.user_id = ?)';
            $parameters = [$after['conversation'], $cursor, $limit, $target];
        }

        if ($stage >= 2 && $this->connection->createSchemaManager()->tablesExist(['import_private_files'])) {
            if ($stage === 2) {
                $sql = str_replace('c.subject', 'COALESCE(c.imported_subject, c.subject) AS subject', $sql);
            } else {
                $sql = str_replace('message.content', 'COALESCE(message.imported_content, message.content)', $sql);
            }
        }

        $order = $stage === 2 ? 'c.id' : ($stage === 3 ? 'message.id' : 'id');
        $rows = $this->connection->fetchAllAssociative($sql . ' ORDER BY ' . $order . ' LIMIT 26', $parameters);
        $body = '';
        $count = 0;

        foreach (array_slice($rows, 0, 25) as $row) {
            if ($row[$text] === null) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

            if (strlen($line) > 2097152) {
                throw new RegistrationRejected(['privacy_export_large']);
            }

            if (strlen($body) + strlen($line) > 2097152) {
                break;
            }

            $body .= $line;
            $after['cursor'] = (int) $row['id'];
            ++$count;
        }

        if (count($rows) === $count) {
            $after['cursor'] = 0;

            if ($stage === 3) {
                $after['last_conversation'] = $after['conversation'];
                $after['conversation'] = 0;
            } else {
                ++$after['stage'];
            }
        }

        return $this->part($state['job'], $after, $body, $count);
    }

    private function part(string $job, array $after, string $body, int $rows): array
    {
        return ['body' => $body, 'header' => ['_export_chunk' => 1, 'job' => $job, 'rows' => $rows,
            'hash' => hash('sha256', $body), 'stage' => $after['stage'], 'cursor' => $after['cursor'],
            'conversation' => $after['conversation'], 'last_conversation' => $after['last_conversation']]];
    }

    private function readPart(string $path, string $job): array
    {
        if (is_link($path) || filesize($path) > 2105344) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $bytes = file_get_contents($path);
        $position = is_string($bytes) ? strpos($bytes, "\n") : false;

        if ($position === false || $position > 8192) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $header = json_decode(substr($bytes, 0, $position), true, 8, JSON_THROW_ON_ERROR);
        $body = substr($bytes, $position + 1);

        if (($header['job'] ?? null) !== $job || ($header['hash'] ?? null) !== hash('sha256', $body)) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        foreach (['rows', 'stage', 'cursor', 'conversation', 'last_conversation'] as $field) {
            if (!is_int($header[$field] ?? null) || $header[$field] < 0) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
        }

        if ($header['rows'] > 25 || $header['stage'] > 4 || ($header['_export_chunk'] ?? null) !== 1) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        return ['header' => $header, 'body' => $body];
    }

    private function finish(string $directory, array $state, Closure $guard): bool
    {
        $final = $directory . '/data.zip';

        if (is_file($final) && !is_link($final)) {
            // A rename may have completed before a crashed/expired attempt acknowledged readiness.
            $check = new ZipArchive();

            if ($check->open($final, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            try {
                $metadata = $check->getFromName('export.json', 2097153);
                $metadata = is_string($metadata) && strlen($metadata) <= 2097152
                    ? json_decode($metadata, true, 16, JSON_THROW_ON_ERROR) : null;

                if (
                    !is_array($metadata) || ($metadata['job'] ?? null) !== $state['job']
                    || ($metadata['complete'] ?? false) !== true || ($metadata['parts'] ?? null) !== $state['parts']
                    || $check->numFiles !== count($state['parts']) + 2
                        + count($state['profile']['community']['files'] ?? [])
                ) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }
            } finally {
                $check->close();
            }

            $hash = hash_file('sha256', $final);

            if (!is_string($hash)) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            $state['zip_hash'] = $hash;
            $state['status'] = 'ready';
            $guard();
            $this->save($directory . '/state.json', $state);

            return true;
        }

        $temporary = $directory . '/build.zip';
        $zip = new ZipArchive();

        if (is_link($temporary) || is_link($final) || $zip->open($temporary, ZipArchive::CREATE) !== true) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $index = $state['zip_index'] ?? 0;
        $mediaIndex = $state['media_index'] ?? 0;
        $mediaFiles = $state['profile']['community']['files'] ?? [];
        $added = 0;
        $bytes = 0;

        try {
            if ($index === 0) {
                if ($zip->locateName('profile.json') !== false) {
                    $zip->deleteName('profile.json');
                }

                if (!$zip->addFromString('profile.json', json_encode($state['profile'], JSON_THROW_ON_ERROR))) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }
            }

            while ($index < count($state['parts']) && $added < 50) {
                $entry = $state['parts'][$index];
                $guard();
                $path = $directory . '/parts/' . $entry['name'];
                $part = $this->readPart($path, $state['job']);

                if ($part['header']['hash'] !== $entry['hash']) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }

                if ($added > 0 && $bytes + strlen($part['body']) > 1048576) {
                    break;
                }

                $name = 'data/' . $entry['name'];
                if ($zip->locateName($name) !== false) {
                    $zip->deleteName($name);
                }

                if (!$zip->addFile($path, $name)) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }

                $bytes += strlen($part['body']);
                ++$index;
                ++$added;
            }

            if ($index === count($state['parts']) && $mediaIndex < count($mediaFiles)) {
                $guard();
                (new CommunityExport($this->connection, $this->storage))->append($zip, $mediaFiles[$mediaIndex]);
                ++$mediaIndex;
            }

            if ($index === count($state['parts']) && $mediaIndex === count($mediaFiles)) {
                $metadata = ['format' => 2, 'job' => $state['job'], 'collection_started' => $state['started'],
                    'collection_finished' => time(), 'snapshot' => 'bounded_ids_collected_across_requests',
                    'rows' => $state['rows'],
                    'restricted_sections' => $state['private'] ? [] : ['conversations', 'messages'],
                    'parts' => $state['parts'], 'complete' => true];
                if ($zip->locateName('export.json') !== false) {
                    $zip->deleteName('export.json');
                }

                if (!$zip->addFromString('export.json', json_encode($metadata, JSON_THROW_ON_ERROR))) {
                    throw new RegistrationRejected(['privacy_export_failed']);
                }
            }
        } finally {
            $closed = $zip->close();
        }

        if (!$closed || !chmod($temporary, 0600)) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $guard();
        $state['zip_index'] = $index;
        $state['media_index'] = $mediaIndex;
        $this->save($directory . '/state.json', $state);

        if ($index === count($state['parts']) && $mediaIndex === count($mediaFiles)) {
            if (!rename($temporary, $final)) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }

            // Readiness is independently replayable after the final rename.
            return $this->finish($directory, $state, $guard);
        }

        return false;
    }

    private function state(string $job): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) {
            return null;
        }

        $directory = $this->root() . '/job-' . $job;

        if (is_link($directory) || is_link($directory . '/parts')) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        if (!is_file($directory . '/state.json')) {
            return null;
        }

        $state = $this->read($directory . '/state.json');

        if (
            ($state['job'] ?? null) !== $job || !is_int($state['actor'] ?? null) || $state['actor'] < 1
            || !is_int($state['target'] ?? null) || $state['target'] < 1 || !is_bool($state['private'] ?? null)
            || ($state['private'] && $state['actor'] !== $state['target']) || !is_int($state['expires'] ?? null)
            || !in_array($state['status'] ?? null, ['pending', 'ready', 'failed'], true)
            || !is_array($state['bounds'] ?? null) || count($state['bounds']) !== 4
            || !is_array($state['parts'] ?? null) || count($state['parts']) > 10000
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        foreach (['stage', 'cursor', 'conversation', 'last_conversation', 'bytes', 'rows'] as $field) {
            if (!is_int($state[$field] ?? null) || $state[$field] < 0) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
        }

        if (
            $state['stage'] > 4 || $state['bytes'] > 67108864
            || !is_int($state['zip_index'] ?? 0) || ($state['zip_index'] ?? 0) > count($state['parts'])
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        if (
            $state['status'] === 'ready' && (!is_string($state['zip_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['zip_hash']) !== 1)
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        foreach ($state['bounds'] as $bound) {
            if (!is_int($bound) || $bound < 0) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
        }

        foreach ($state['parts'] as $entry) {
            if (
                !is_array($entry) || !is_string($entry['name'] ?? null)
                || preg_match('/^(?:posts|threads|conversations|messages)-[0-9]{1,5}\.jsonl$/D', $entry['name']) !== 1
                || !is_string($entry['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $entry['hash']) !== 1
            ) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
        }

        return $state;
    }

    private function read(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 2097152) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        if (!is_array($value)) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        return $value;
    }

    private function save(string $path, array $value): void
    {
        $this->write($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function write(string $path, string $bytes): void
    {
        if (is_link($path)) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = fopen($temporary, 'x+b');

        if ($stream === false) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        try {
            if (
                !chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes)
                || !fflush($stream) || !fsync($stream)
            ) {
                throw new RegistrationRejected(['privacy_export_failed']);
            }
        } catch (Throwable $failure) {
            fclose($stream);
            @unlink($temporary);

            throw $failure;
        }

        fclose($stream);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RegistrationRejected(['privacy_export_failed']);
        }
    }

    private function root(): string
    {
        $storage = realpath($this->storage);
        $public = realpath($this->public);
        $key = static fn(string $path): string => strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $path));

        if ($storage === false || $public === false || str_starts_with($key($storage) . '/', $key($public) . '/')) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $root = $storage . '/privacy-jobs';

        if (
            is_link($root) || (!is_dir($root) && !mkdir($root, 0700))
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($root) & 0077) !== 0)
        ) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        return $root;
    }

    /** Conservative private-disk guard, allowing roughly one ZIP plus source parts per retained job. */
    private function storageBudget(int $extra): void
    {
        $bytes = $extra;

        foreach (array_slice(glob($this->root() . '/job-*/state.json') ?: [], 0, 32) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $state = $this->read($file);
            $bytes += is_int($state['bytes'] ?? null) ? $state['bytes'] : 67108864;
        }

        if ($bytes * 2 > 268435456) {
            throw new RegistrationRejected(['privacy_export_busy']);
        }
    }

    public function garbageCollect(): void
    {
        $this->cleanup($this->root());
    }

    private function cleanup(string $root): void
    {
        $remaining = 64;

        foreach (array_slice(glob($root . '/job-*') ?: [], 0, 32) as $directory) {
            if (
                is_link($directory) || is_link($directory . '/parts') || is_link($directory . '/lock')
                || !is_file($directory . '/state.json')
            ) {
                continue;
            }

            $state = $this->read($directory . '/state.json');

            $consumed = ($state['status'] ?? '') === 'ready' && !is_file($directory . '/data.zip');

            if (!$consumed && ($state['expires'] ?? PHP_INT_MAX) >= time()) {
                continue;
            }

            $lock = fopen($directory . '/lock', 'c+b');

            if ($lock === false) {
                continue;
            }

            $empty = false;

            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }

                foreach (array_slice(glob($directory . '/parts/*') ?: [], 0, $remaining) as $part) {
                    if (!is_link($part) && is_file($part) && unlink($part)) {
                        --$remaining;
                    }
                }

                $empty = (glob($directory . '/parts/*') ?: []) === [];

                if ($empty) {
                    foreach (['data.zip', 'build.zip', 'state.json'] as $file) {
                        if (!is_link($directory . '/' . $file) && is_file($directory . '/' . $file)) {
                            @unlink($directory . '/' . $file);
                        }
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            if ($empty) {
                @unlink($directory . '/lock');
                @rmdir($directory . '/parts');
                @rmdir($directory);
            }

            if ($remaining <= 0) {
                break;
            }
        }
    }
}
