<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Nolviko\Core\Mail\MailDeliveryFailed;

/**
 * Mail about accounts (M02.03). Links point at the configured site address, never at the request's Host header.
 */
interface AccountMail
{
    /**
     * @throws MailDeliveryFailed
     */
    public function verification(string $to, string $username, string $token): void;

    /**
     * Sent instead of a second account when someone registers with an address that has one already.
     *
     * @throws MailDeliveryFailed
     */
    public function alreadyRegistered(string $to, string $username): void;

    /**
     * The link to choose a new password (M02.05).
     *
     * @throws MailDeliveryFailed
     */
    public function passwordReset(string $to, string $username, string $token): void;

    /**
     * The link that confirms a new address, sent to that address (M02.16).
     *
     * @throws MailDeliveryFailed
     */
    public function emailChangeConfirm(string $to, string $username, string $token): void;

    /**
     * A notice to the old address that a change was asked for (M02.16).
     *
     * @throws MailDeliveryFailed
     */
    public function emailChangeNotice(string $to, string $username): void;
}
