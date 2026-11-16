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
}
