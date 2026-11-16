<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\ViewModel;

/**
 * One post on a thread page (M03.10). `html` comes from the render cache (PostRenderer), the only unescaped
 * output. `status` is null for visible posts and the marker (`pending`, `hidden`) moderators see otherwise.
 * `permalink` is the post's stable URL (`/posts/{id}`, M03.11); `reportUrl` is null when the reader may not
 * report it (guest, own post, no `forum.post.report`, not visible; M04.07a). `avatarUrl` and `profileUrl` are null
 * unless the author's profile is visible to this reader (community privacy and blocks, M07); themes fall back to
 * the initial. `quoteUrl` opens the reply form pre-filled with this post (null when the reader may not reply).
 */
final readonly class PostView implements ViewModel
{
    public function __construct(
        public int $id,
        public ?string $authorName,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $editedAt,
        public SafeHtml $html,
        public ?string $status,
        public string $permalink,
        public ?string $reportUrl = null,
        public ?string $avatarUrl = null,
        public ?string $profileUrl = null,
        public ?string $quoteUrl = null,
        public ?string $historyUrl = null,
        public ?array $authorRank = null,
        public ?string $editUrl = null,
        /** Place in the thread (2026-11-14): `#1` for the first post; the permalink still uses the id. */
        public ?int $number = null,
    ) {
    }
}
