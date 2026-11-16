<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use Nolviko\Core\View\ViewModel;

/**
 * One row of a forum page's thread list (M03.11). `url` points at the thread, or at the moved thread for a
 * redirect stub (`moved`); `status` is null for visible threads and the marker moderators see otherwise;
 * `unread` marks a thread with posts the member has not seen (M03.12). `replies` is the stored visible reply
 * counter (null for redirect stubs).
 */
final readonly class ThreadRowView implements ViewModel
{
    public function __construct(
        public string $title,
        public string $url,
        public bool $sticky,
        public bool $locked,
        public bool $moved,
        public ?DateTimeImmutable $lastPostAt,
        public ?string $authorName,
        public ?string $lastPosterName,
        public ?string $status,
        public bool $unread = false,
        public ?int $replies = null,
        public ?int $views = null,
        public ?array $prefix = null,
        public ?string $lastPosterAvatar = null,
    ) {
    }
}
