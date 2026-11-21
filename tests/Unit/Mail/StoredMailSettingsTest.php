<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Mail;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Mail\StoredMailSettings;
use Nolviko\Core\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

/** ADR-034 amendment 2026-11-21: SMTP from the panel, password sealed, config/local.php wins. */
final class StoredMailSettingsTest extends TestCase
{
    public function testPanelSmtpAppliesWithTheSealedPassword(): void
    {
        $config = self::config(new MailSettings());
        $sealed = StoredMailSettings::seal($config->appSecret, 'smtp-pass');
        self::assertStringNotContainsString('smtp-pass', $sealed);
        self::assertSame($sealed, SiteSettings::normalize('mail.smtp_password', $sealed));

        $mail = StoredMailSettings::resolve($config, [
            'mail.transport' => 'smtp', 'mail.smtp_host' => 'email-smtp.eu-central-1.amazonaws.com',
            'mail.smtp_user' => 'AKIA', 'mail.smtp_password' => $sealed, 'mail.from_address' => 'no-reply@example.com',
        ]);

        self::assertSame(MailSettings::SMTP, $mail->transport);
        self::assertSame('email-smtp.eu-central-1.amazonaws.com', $mail->smtpHost);
        self::assertSame(587, $mail->port());
        self::assertSame('smtp-pass', $mail->smtpPassword->reveal());
        self::assertSame('no-reply@example.com', $mail->fromAddress);
    }

    public function testConfigSmtpWinsAndAnotherSecretCannotOpenThePassword(): void
    {
        $config = self::config(new MailSettings(MailSettings::SMTP, null, null, 'smtp.local', 465, 'smtps'));
        $mail = StoredMailSettings::resolve($config, ['mail.transport' => 'smtp', 'mail.smtp_host' => 'panel.example']);

        self::assertTrue(StoredMailSettings::pinned($config));
        self::assertSame('smtp.local', $mail->smtpHost);
        self::assertNull(StoredMailSettings::open(new Secret(str_repeat('b', 32)), StoredMailSettings::seal(
            $config->appSecret,
            'x',
        )));
        self::assertSame(MailSettings::MAIL, StoredMailSettings::resolve(self::config(new MailSettings()), [
            'mail.transport' => 'smtp',
        ])->transport);
    }

    private static function config(MailSettings $mail): Config
    {
        return new Config(
            'mysql',
            'db',
            null,
            'forum',
            'forum',
            new Secret('p'),
            new Secret(str_repeat('a', 32)),
            mail: $mail
        );
    }
}
