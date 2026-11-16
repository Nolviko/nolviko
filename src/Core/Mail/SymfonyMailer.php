<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use Nolviko\Core\Config\MailSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * Mailer on symfony/mailer (ADR-028); the only class that builds Symfony Mime messages. A failure is logged
 * with the transport name and the exception class only (no server reply, address or credential) and rethrown
 * as MailDeliveryFailed.
 */
final class SymfonyMailer implements Mailer
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly MailSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(MailMessage $message): void
    {
        if ($this->settings->fromAddress === null) {
            $this->logger->error('mail_not_configured', ['transport' => $this->settings->transport]);

            throw new MailDeliveryFailed('mail_not_configured');
        }

        try {
            $email = (new Email())
                ->from(new Address($this->settings->fromAddress, $this->settings->fromName ?? ''))
                ->to($message->to)
                ->subject($message->subject)
                ->text($message->text)
                ->html($message->html);
            $this->transport->send($email);
        } catch (TransportExceptionInterface | MimeException $exception) {
            $this->logger->error('mail_send_failed', [
                'transport' => $this->settings->transport,
                'error' => $exception::class,
            ]);

            throw new MailDeliveryFailed('mail_send_failed');
        }
    }
}
