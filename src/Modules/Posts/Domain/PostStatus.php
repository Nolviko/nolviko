<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

/**
 * Who may see a post: everyone allowed in its forum (`visible`), moderators only while it waits for approval
 * (`pending`) or after it was hidden or soft-deleted (`hidden`; M03.07 remembers the status it replaced).
 */
enum PostStatus: string
{
    case Visible = 'visible';
    case Pending = 'pending';
    case Hidden = 'hidden';
}
