<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use DateTimeImmutable;
use Nolviko\Core\View\ViewModel;

/**
 * One board index row for `forums/index.twig` (M03.09). `url` is the forum page (null for categories, M03.11);
 * `newThreadUrl` is null when the reader may not start threads there; the last-post fields are null when there
 * is none to show.
 */
final readonly class ForumRowView implements ViewModel
{
    public function __construct(
        public int $id,
        public bool $category,
        public string $title,
        public int $depth,
        public int $threadCount,
        public int $postCount,
        public ?DateTimeImmutable $lastPostAt,
        public ?string $lastThreadTitle,
        public ?string $lastPosterName,
        public ?string $newThreadUrl,
        public ?string $url = null,
        public ?string $lastThreadUrl = null,
        public ?string $description = null,
        public array $subforums = [],
    ) {
    }
}
