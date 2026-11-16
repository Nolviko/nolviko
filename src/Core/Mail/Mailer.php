<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

/**
 * Sends one mail synchronously within the request (M02.02a). Callers show `diagnostics.mail_send_failed` when
 * it throws; the outbox (M08) may take over non-urgent mail later without changing this contract.
 */
interface Mailer
{
    /**
     * @throws MailDeliveryFailed `mail_not_configured` (no sender address) or `mail_send_failed`.
     */
    public function send(MailMessage $message): void;
}
