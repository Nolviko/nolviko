<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * Who may see a thread: everyone allowed in its forum (`visible`), moderators only while it waits for
 * approval (`pending`) or after a moderator hid it (`hidden`).
 */
enum ThreadStatus: string
{
    case Visible = 'visible';
    case Pending = 'pending';
    case Hidden = 'hidden';
}
