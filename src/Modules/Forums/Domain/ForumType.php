<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

/**
 * A category groups forums and holds no threads; a forum holds threads (M03.02 enforces it).
 */
enum ForumType: string
{
    case Category = 'category';
    case Forum = 'forum';

    /** A link section (beta.18): an external address shown in the forum list; holds no threads. */
    case Link = 'link';
}
