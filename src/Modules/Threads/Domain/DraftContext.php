<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * Names of the places a draft belongs to (M03.14).
 */
final class DraftContext
{
    public static function thread(int $forumId): string
    {
        return 'thread:' . $forumId;
    }

    public static function reply(int $threadId): string
    {
        return 'reply:' . $threadId;
    }
}
