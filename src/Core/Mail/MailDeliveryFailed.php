<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use RuntimeException;

/**
 * A mail could not be handed to the transport. The message is a diagnostic ID (`mail_send_failed`,
 * `mail_not_configured`), never the transport's own text, which could contain server replies or addresses.
 */
final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $diagnostic)
    {
        parent::__construct($diagnostic);
    }
}
