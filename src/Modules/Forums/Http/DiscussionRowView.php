<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use DateTimeImmutable;
use Nolviko\Core\View\ViewModel;

/**
 * One row of the discussions home page (DiscussionFeed): the thread, its activity line and its forum label.
 */
final readonly class DiscussionRowView implements ViewModel
{
    public function __construct(
        public string $title,
        public string $url,
        public bool $locked,
        public ?DateTimeImmutable $lastPostAt,
        public ?string $authorName,
        public ?string $lastPosterName,
        public int $replies,
        public ?string $forumTitle,
        public ?string $forumUrl,
        public bool $unread = false,
        public int $views = 0,
        public ?array $prefix = null,
        public array $tags = [],
        public ?string $lastPosterAvatar = null,
    ) {
    }
}
