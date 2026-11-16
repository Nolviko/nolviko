<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

/**
 * One outgoing mail (ADR-028): a single recipient, a translated subject, and plain text plus HTML bodies that
 * were rendered with escaped variables. Library types never appear here, so modules depend on this DTO only.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
    ) {
    }
}
