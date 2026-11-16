<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use RuntimeException;

/** Rewrite only identified attachment tags, after the attachment phase. Ambiguous tags stop for source review. */
final readonly class InlineAttachments
{
    public function __construct(private Connection $db, private UrlGenerator $urls, private string $appUrl)
    {
    }

    public function rewrite(string $job, int $sourcePost, string $body, string $engine): string
    {
        $pattern = match ($engine) {
            'smf' => '~\[attach(?:=[^\]\r\n]{0,100})?\]([0-9]{1,19})\[/attach\]~i',
            'mybb' => '~\[attachment=([0-9]{1,19})\]~i',
            'phpbb' => '~\[attachment=([0-9]{1,3})\]([^\r\n]{0,255}?)\[/attachment\]~i',
        };
        return preg_replace_callback($pattern, function (array $match) use ($job, $sourcePost, $engine): string {
            if ($engine === 'phpbb') {
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
            return '[url=' . $origin . $path . ']' . $source . '[/url]';
        }, $body);
    }
}
