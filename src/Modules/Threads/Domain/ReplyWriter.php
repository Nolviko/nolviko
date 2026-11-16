<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * Writes a reply atomically (M03.05): the idempotency key, the post with its cached rendering, the thread's
 * last-post data and reply count under the thread row lock, forum statistics for visible posts and the
 * `post.created` outbox event. Any failure leaves nothing behind.
 */
interface ReplyWriter
{
    /**
     * @return CreatedReply With `repeated` = true and the earlier post when the token was used before.
     *
     * @throws ThreadRuleViolation `thread_not_found`, `thread_locked`, `post_flood_wait` (`seconds`) or
     *     `post_token_invalid` (token used before, but not for a post of this thread that still exists).
     */
    public function reply(NewReply $reply): CreatedReply;
}
