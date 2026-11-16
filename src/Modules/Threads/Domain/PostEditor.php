<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * Stores an edit atomically (M03.06): the revision with the previous content and reason, the new content with
 * its rendering, and the `post.edited` outbox event.
 */
interface PostEditor
{
    /**
     * @throws ThreadRuleViolation `post_not_found` when the post disappeared meanwhile.
     */
    public function edit(EditedPost $edit): void;

    /** Sets the thread's title and slug (2026-11-09), audited. */
    public function retitle(int $threadId, string $title, string $slug, int $editorId, DateTimeImmutable $at): void;
}
