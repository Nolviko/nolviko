<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * Account lifecycle (DATABASE.md `users.status`; the registration queue is `pending_approval`).
 */
enum UserStatus: string
{
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
}
