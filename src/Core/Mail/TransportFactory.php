<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use Nolviko\Core\Config\MailSettings;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The configured transport (ADR-028). SMTP always uses TLS: implicit TLS for `smtps`, and for `starttls` the
 * connection fails when the server does not offer STARTTLS, so credentials never travel in plain text. Besides
 * ConnectionFactory and the Redactor this is the only place that reveals a Secret (the SMTP password).
 */
final class TransportFactory
{
    /** Seconds to wait for the SMTP server; a web request has 30 s in total on shared hosting. */
    public const SMTP_TIMEOUT = 10;

    public static function create(MailSettings $settings, int $timeout = self::SMTP_TIMEOUT): TransportInterface
    {
        if ($settings->transport !== MailSettings::SMTP) {
            return new PhpMailTransport();
        }

        $transport = new EsmtpTransport(
            (string) $settings->smtpHost,
            $settings->port(),
            $settings->smtpEncryption === MailSettings::SMTPS,
        );
        $transport->setRequireTls(true);
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout(max(1, min(self::SMTP_TIMEOUT, $timeout)));
        }

        if ($settings->smtpUser !== null && $settings->smtpUser !== '') {
            $transport->setUsername($settings->smtpUser);
            $transport->setPassword($settings->smtpPassword->reveal());
        }

        return $transport;
    }
}
