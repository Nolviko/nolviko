<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * Writes a thread with its first post atomically (M03.04): thread row, post row with its cached rendering,
 * first/last post IDs, forum statistics for visible content and the `thread.created` outbox event. Any failure
 * leaves nothing behind.
 */
interface ThreadWriter
{
    /**
     * @throws ThreadRuleViolation `post_flood_wait` (`seconds`), `thread_forum_not_found` or
     *     `thread_forum_is_category`.
     */
    public function create(NewThread $thread): CreatedThread;
}
