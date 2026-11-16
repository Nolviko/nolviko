<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * A validated, permitted edit, ready to be stored with its revision.
 */
final class EditedPost
{
    public function __construct(
        public readonly int $postId,
        public readonly int $editorId,
        public readonly string $content,
        public readonly string $contentFormat,
        public readonly string $renderedHtml,
        public readonly int $rendererVersion,
        public readonly ?string $reason,
        public readonly DateTimeImmutable $editedAt,
    ) {
    }
}
