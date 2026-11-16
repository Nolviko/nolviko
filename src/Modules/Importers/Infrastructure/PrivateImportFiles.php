<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Private attachment copies inherit current conversation membership; sender/admin status grants no bypass. */
final readonly class PrivateImportFiles
{
    public function __construct(private Connection $db, private ImportFileStore $files)
    {
    }

    public function listing(int $actor, int $conversation, ?string $before = null): array
    {
        if ($before !== null && preg_match('/^[a-f0-9]{32}$/D', $before) !== 1) {
            throw new RegistrationRejected(['conversation_not_found']);
        }
        return $this->db->fetchAllAssociative(
            'SELECT f.id, f.original_name, f.bytes FROM import_private_files f '
            . 'INNER JOIN import_jobs j ON j.id = f.job_id AND j.state = ? WHERE f.owner_id = ? '
            . 'AND f.conversation_id = ? AND EXISTS (SELECT 1 FROM private_conversation_members m '
            . 'WHERE m.conversation_id = f.conversation_id AND m.user_id = ?)'
            . ($before === null ? '' : ' AND f.id < ?') . ' ORDER BY f.id DESC LIMIT 51',
            ['completed', $actor, $conversation, $actor, ...($before === null ? [] : [$before])]
        );
    }

    public function download(int $actor, string $id): BinaryFileResponse
    {
        if ($actor < 1 || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new RegistrationRejected(['conversation_not_found']);
        }
        $row = $this->db->fetchAssociative('SELECT f.file_key, f.original_name FROM import_private_files f '
            . 'INNER JOIN import_jobs j ON j.id = f.job_id AND j.state = ? WHERE f.id = ? AND f.owner_id = ? '
            . 'AND EXISTS (SELECT 1 FROM private_conversation_members m WHERE m.conversation_id = f.conversation_id '
            . 'AND m.user_id = ?)', ['completed', $id, $actor, $actor]);
        if ($row === false) {
            throw new RegistrationRejected(['conversation_not_found']);
        }
        $path = $this->files->path($row['file_key']);
        if (!is_file($path)) {
            throw new RegistrationRejected(['conversation_not_found']);
        }
        $response = new BinaryFileResponse(
            $path,
            200,
            ['Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Robots-Tag' => 'noindex'],
            public: false,
            autoEtag: false,
            autoLastModified: false
        );
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            str_replace(['/', '\\'], '_', $row['original_name']),
            preg_replace('/[^A-Za-z0-9._-]/', '_', $row['original_name']) ?: 'file'
        );
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->remove('Last-Modified');
        return $response;
    }

    public function collect(): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, file_key FROM import_private_files '
            . 'WHERE owner_id IS NULL OR conversation_id IS NULL ORDER BY id LIMIT 10');
        foreach ($rows as $row) {
            $used = $this->db->fetchOne('SELECT id FROM import_private_files WHERE file_key = ? '
                . 'AND owner_id IS NOT NULL AND conversation_id IS NOT NULL LIMIT 1', [$row['file_key']]);
            if ($used === false) {
                $path = $this->files->path($row['file_key']);
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('import_attachment_cleanup_failed');
                }
            }
            $this->db->delete('import_private_files', ['id' => $row['id']]);
        }
    }
}
