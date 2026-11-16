<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use DateTimeImmutable;
use Nolviko\Core\View\ViewModel;

/**
 * One queue row for `moderation/queue.twig` (M04.07); fields that do not apply to the row's kind are null.
 */
final readonly class QueueRowView implements ViewModel
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public ?string $title,
        public ?string $excerpt,
        public ?string $authorName,
        public ?string $reason,
        public ?string $reporterName,
        public ?string $username,
        public ?string $email,
        public ?string $postUrl,
        public ?string $actionUrl,
    ) {
    }
}
