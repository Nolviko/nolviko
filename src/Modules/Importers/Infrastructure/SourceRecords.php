<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use InvalidArgumentException;
use Nolviko\Modules\Importers\Domain\ImportText;
use RuntimeException;

/**
 * Source side of contract 1 (ADR-038, plan step 3, 2026-11-20): turns a source's raw rows into the records the core
 * import target accepts. Moves to the importer extension together with the adapters.
 *
 * - `_hash`: SHA-256 of the raw row exactly as the adapter read it, so the target notices a changed source without
 *   knowing its format (the same value the core computed itself before, so jobs started earlier continue).
 * - Text: post bodies, signatures and private-message bodies are converted from the forum's markup (BBCode,
 *   phpBB storage format and its s9e XML, HTML entities) to Nolviko markup here; the target only checks it.
 * - Inline attachments in posts become neutral markers the target resolves through its ID map:
 *   `[nolviko-attachment]<source attachment ID>[/nolviko-attachment]` (SMF, MyBB) and
 *   `[nolviko-attachment-name]<file name as stored>[/nolviko-attachment-name]` (phpBB, which names files per post).
 */
final class SourceRecords
{
    public const ATTACHMENT = '~\[nolviko-attachment\]([0-9]{1,19})\[/nolviko-attachment\]~';

    public const ATTACHMENT_NAME = '~\[nolviko-attachment-name\]([^\r\n]{0,255}?)\[/nolviko-attachment-name\]~';

    /** @return list<array<string, mixed>> */
    public static function normalize(string $engine, string $phase, array $rows): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                throw new RuntimeException('import_source_order_invalid');
            }
            $row['_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            try {
                if ($phase === 'posts') {
                    $body = ImportText::body((string) $row['body'], $engine, 'UTF-8');
                    $row['body'] = self::attachments($body, $engine);
                } elseif ($phase === 'users') {
                    $row['signature'] = ImportText::body((string) ($row['signature'] ?? ''), $engine, 'UTF-8');
                } elseif (str_starts_with($phase, 'private_') && $phase !== 'private_files') {
                    $row['body'] = ImportText::body((string) $row['body'], $engine, 'UTF-8');
                }
            } catch (InvalidArgumentException $failure) {
                throw new RuntimeException($failure->getMessage(), 0, $failure);
            }
        }
        unset($row);
        return $rows;
    }

    private static function attachments(string $body, string $engine): string
    {
        return (string) match ($engine) {
            'smf' => preg_replace(
                '~\[attach(?:=[^\]\r\n]{0,100})?\]([0-9]{1,19})\[/attach\]~i',
                '[nolviko-attachment]$1[/nolviko-attachment]',
                $body
            ),
            'mybb' => preg_replace(
                '~\[attachment=([0-9]{1,19})\]~i',
                '[nolviko-attachment]$1[/nolviko-attachment]',
                $body
            ),
            'phpbb' => preg_replace(
                '~\[attachment=[0-9]{1,3}\]([^\r\n]{0,255}?)\[/attachment\]~i',
                '[nolviko-attachment-name]$1[/nolviko-attachment-name]',
                $body
            ),
            default => throw new RuntimeException('import_engine_unsupported'),
        };
    }
}
