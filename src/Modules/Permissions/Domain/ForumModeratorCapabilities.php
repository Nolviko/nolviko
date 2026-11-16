<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/** Local-role defaults only: never administration, user discipline or implicit private-forum visibility. */
final class ForumModeratorCapabilities
{
    public const KEYS = [
        'moderation.post.hide',
        'moderation.post.edit_any',
        'moderation.thread.lock',
        'moderation.thread.sticky',
        'moderation.thread.move',
        'moderation.report.handle',
    ];
}
