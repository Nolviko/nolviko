<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Domain\ImportRecords;
use RuntimeException;

/**
 * Rewrites the neutral attachment markers a source left in post bodies (contract 1, ImportRecords) into links to the
 * imported files, after the attachment phase. A marker that names no single imported file of that post stops the job.
 */
final readonly class InlineAttachments
{
    public function __construct(private Connection $db, private UrlGenerator $urls, private string $appUrl)
    {
    }

    public static function has(string $body): bool
    {
        return preg_match(ImportRecords::ATTACHMENT, $body) === 1
            || preg_match(ImportRecords::ATTACHMENT_NAME, $body) === 1;
    }

    public function rewrite(string $job, int $sourcePost, string $body): string
    {
        $body = (string) preg_replace_callback(
            ImportRecords::ATTACHMENT,
            fn(array $match): string => $this->link($job, $sourcePost, $match),
            $body
        );
        return (string) preg_replace_callback(
            ImportRecords::ATTACHMENT_NAME,
            fn(array $match): string => $this->link($job, $sourcePost, ['', '', $match[1]], true),
            $body
        );
    }

    /** @param array<int, string> $match [whole, source ID] or, by name, ['', '', file name] */
    private function link(string $job, int $sourcePost, array $match, bool $byName = false): string
    {
        if ($byName) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT source_id, target_id, meta_json FROM import_map '
                . 'WHERE job_id = ? AND entity = ? AND source_parent = ? ORDER BY source_id DESC LIMIT 101',
                [$job, 'attachments', $sourcePost]
            );
            if (count($rows) > 100) {
                throw new RuntimeException('import_inline_attachment_ambiguous');
            }
            $matches = [];
            foreach ($rows as $row) {
                $meta = json_decode($row['meta_json'], true, 8, JSON_THROW_ON_ERROR);
                if (
                    ($meta['source_name'] ?? null) === html_entity_decode(
                        $match[2],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )
                ) {
                    $matches[] = $row;
                }
            }
            if (count($matches) !== 1) {
                throw new RuntimeException('import_inline_attachment_ambiguous');
            }
            $source = ImportCursor::parts($matches[0]['source_id'])[0];
            $target = $matches[0]['target_id'];
        } else {
            $source = (int) $match[1];
            $target = $this->db->fetchOne(
                'SELECT target_id FROM import_map WHERE job_id = ? AND entity = ? '
                . 'AND source_id = ? AND source_parent = ?',
                [$job, 'attachments', ImportCursor::key([$source]), $sourcePost]
            );
        }
        if (!is_string($target) || $target === '0') {
            throw new RuntimeException('import_inline_attachment_missing');
        }
            $parts = parse_url($this->appUrl);
        if (
                !is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new RuntimeException('import_canonical_url_required');
        }
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $path = $this->urls->generate(
                CoreRoutes::LEGACY_RECORD,
                ['job' => $job, 'entity' => 'attachments', 'source' => $source]
            );
            $url = $origin . $path;
            // An imported picture is shown in the post (beta.18); other files stay a download link.
            $mime = $this->db->fetchOne('SELECT mime FROM community_media WHERE id = ?', [$target]);
            return in_array($mime, ImportAuxWriter::IMAGES, true)
                ? '[img]' . $url . '[/img]' : '[url=' . $url . ']' . $source . '[/url]';
    }
}
