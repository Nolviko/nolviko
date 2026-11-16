<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Mail;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Mail\MailMessage;
use Nolviko\Core\Mail\PhpMailTransport;
use Nolviko\Core\Mail\SymfonyMailer;
use Nolviko\Core\Mail\TransportFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\CapturingTransport;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * M02.02a: messages are built from translated, escaped templates and handed to the transport synchronously;
 * failures become one diagnostic and a log entry without secrets; SMTP always requires TLS; `mail()` gets the
 * MIME message without duplicate To/Subject headers. No network is touched.
 */
final class MailerTest extends TestCase
{
    private const PASSWORD = 'smtp-password-that-must-stay-hidden';

    /** @var list<array{string, string, array<mixed>}> */
    private array $logs = [];

    public function testComposedMailIsTranslatedAndEscaped(): void
    {
        $message = $this->composer()->compose('test', 'mail.test.subject', 'tom@example.com', [
            'name' => '<b>Tom & "Jerry"</b>',
        ]);

        self::assertSame('tom@example.com', $message->to);
        self::assertSame('Test message from your forum', $message->subject);
        self::assertStringContainsString('Hello <b>Tom & "Jerry"</b>,', $message->text, 'plain text is not escaped');
        self::assertStringNotContainsString('&amp;', $message->text);
        self::assertStringContainsString('Hello &lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;,', $message->html);
        self::assertStringNotContainsString('<b>Tom', $message->html, 'HTML is escaped');
        self::assertStringContainsString('<html lang="en">', $message->html);
    }

    public function testSubjectStaysOneLine(): void
    {
        $message = $this->composer()->compose('test', 'mail.test.subject', 'a@example.com', ['name' => 'A']);
        $injected = new MailMessage('a@example.com', "Hi\r\nBcc: evil@example.com", 't', '<p>t</p>');
        $transport = new CapturingTransport();
        $this->mailer($transport, new MailSettings(fromAddress: 'forum@example.com'))->send($injected);
        $raw = $transport->sent[0]->toString();

        self::assertStringNotContainsString("\n", $message->subject);
        self::assertSame(0, preg_match('/^Bcc:/mi', $raw), 'a line break in a subject cannot add a header');
    }

    public function testMessageIsSentWithSenderAndBothParts(): void
    {
        $transport = new CapturingTransport();
        $settings = new MailSettings(fromAddress: 'forum@example.com', fromName: 'Example forum');
        $this->mailer($transport, $settings)->send(new MailMessage('b@example.com', 'Subject', 'Plain', '<p>Rich</p>'));

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('"Example forum" <forum@example.com>', $email->getFrom()[0]->toString());
        self::assertSame('b@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Plain', $email->getTextBody());
        self::assertSame('<p>Rich</p>', $email->getHtmlBody());
        self::assertSame([], $this->logs);
    }

    public function testFailuresBecomeOneDiagnosticAndASecretFreeLog(): void
    {
        $transport = new CapturingTransport('535 authentication failed for ' . self::PASSWORD);
        $settings = new MailSettings(
            MailSettings::SMTP,
            'forum@example.com',
            smtpHost: 'smtp.example.com',
            smtpPassword: new Secret(self::PASSWORD),
        );

        self::assertSame('mail_send_failed', $this->failure($transport, $settings));
        self::assertSame('mail_send_failed', $this->logs[0][1]);
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($this->logs));
        self::assertStringNotContainsString('535', (string) json_encode($this->logs), 'no server reply is logged');
        self::assertSame('mail_not_configured', $this->failure(new CapturingTransport(), new MailSettings()));
        self::assertSame(
            'mail_send_failed',
            $this->failure(new CapturingTransport(), new MailSettings(fromAddress: 'forum@example.com'), 'not-mail'),
        );
    }

    public function testSmtpAlwaysRequiresTls(): void
    {
        $starttls = TransportFactory::create(new MailSettings(
            MailSettings::SMTP,
            'f@example.com',
            smtpHost: 'smtp.example.com',
            smtpUser: 'forum',
            smtpPassword: new Secret(self::PASSWORD),
        ));
        $smtps = TransportFactory::create(new MailSettings(
            MailSettings::SMTP,
            'f@example.com',
            smtpHost: 'smtp.example.com',
            smtpEncryption: MailSettings::SMTPS,
        ));

        self::assertInstanceOf(EsmtpTransport::class, $starttls);
        self::assertTrue($starttls->isTlsRequired());
        self::assertSame('forum', $starttls->getUsername());
        self::assertSame('smtp://smtp.example.com:587', (string) $starttls);
        self::assertInstanceOf(EsmtpTransport::class, $smtps);
        self::assertSame('smtps://smtp.example.com', (string) $smtps);
        self::assertInstanceOf(PhpMailTransport::class, TransportFactory::create(new MailSettings()));
    }

    public function testMailFunctionGetsToAndSubjectSeparately(): void
    {
        $calls = [];
        $transport = new PhpMailTransport(
            static function (string ...$arguments) use (&$calls): bool {
                $calls[] = $arguments;

                return true;
            },
        );
        $this->mailer($transport, new MailSettings(fromAddress: 'forum@example.com'))
            ->send(new MailMessage('b@example.com', 'Grüße aus dem Forum', 'Plain', '<p>Rich</p>'));

        [$to, $subject, $body, $headers, $params] = $calls[0];
        self::assertSame('b@example.com', $to);
        self::assertStringStartsWith('=?utf-8?', strtolower($subject), 'non-ASCII subjects stay encoded');
        self::assertSame(0, preg_match('/^(To|Subject):/mi', $headers), 'mail() adds To and Subject itself');
        self::assertStringContainsString('From: forum@example.com', $headers);
        self::assertStringContainsString('multipart/alternative', $headers);
        self::assertStringContainsString('Plain', $body);
        self::assertSame('-fforum@example.com', $params);

        $refusing = new PhpMailTransport(static fn(): bool => false);
        self::assertSame('mail_send_failed', $this->failure($refusing, new MailSettings(fromAddress: 'f@example.com')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidMail(): iterable
    {
        yield 'transport' => [['transport' => 'sendmail'], 'mail.transport'];
        yield 'smtp without host' => [['transport' => 'smtp'], 'mail.smtp_host'];
        yield 'host with credentials' => [
            ['transport' => 'smtp', 'smtp_host' => 'user@smtp.example.com'],
            'mail.smtp_host',
        ];
        yield 'plaintext smtp' => [['smtp_encryption' => 'none'], 'mail.smtp_encryption'];
        yield 'port' => [['smtp_port' => 70000], 'mail.smtp_port'];
        yield 'sender' => [['from_address' => 'not-an-address'], 'mail.from_address'];
        yield 'sender name with a line break' => [['from_name' => "Forum\r\nBcc: x@example.com"], 'mail.from_name'];
    }

    /**
     * @param array<string, mixed> $mail
     */
    #[DataProvider('invalidMail')]
    public function testInvalidMailSettingsAreRefused(array $mail, string $key): void
    {
        try {
            ConfigLoader::fromArray([...SiteFixture::validConfig(), 'mail' => $mail]);
            self::fail('expected a config error');
        } catch (ConfigException $exception) {
            self::assertContains('config_value_invalid:' . $key, $exception->diagnostics());
        }
    }

    public function testMailSettingsLoadAndStayRedacted(): void
    {
        $config = ConfigLoader::fromArray([...SiteFixture::validConfig(), 'mail' => [
            'transport' => 'smtp',
            'from_address' => 'forum@example.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_encryption' => 'smtps',
            'smtp_user' => 'forum',
            'smtp_password' => self::PASSWORD,
        ]]);

        self::assertSame(465, $config->mail->port());
        self::assertSame(self::PASSWORD, $config->mail->smtpPassword->reveal());
        self::assertStringNotContainsString(self::PASSWORD, print_r($config, true));
        self::assertSame(MailSettings::MAIL, ConfigLoader::fromArray(SiteFixture::validConfig())->mail->transport);
        self::assertSame(
            'smtp.example.org',
            ConfigLoader::fromArray(SiteFixture::validConfig(), [
                'FORUM_MAIL_TRANSPORT' => 'smtp',
                'FORUM_SMTP_HOST' => 'smtp.example.org',
            ])->mail->smtpHost,
        );
    }

    private function failure(
        TransportInterface $transport,
        MailSettings $settings,
        string $to = 'b@example.com',
    ): string {
        try {
            $this->mailer($transport, $settings)->send(new MailMessage($to, 'Subject', 'Plain', '<p>Rich</p>'));
        } catch (MailDeliveryFailed $failure) {
            return $failure->diagnostic;
        }

        self::fail('expected a delivery failure');
    }

    private function mailer(
        TransportInterface $transport,
        MailSettings $settings,
    ): SymfonyMailer {
        $logs = &$this->logs;
        $logger = new class ($logs) extends AbstractLogger {
            /**
             * @param list<array{string, string, array<mixed>}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };

        return new SymfonyMailer($transport, $settings, $logger);
    }

    private function composer(): MailComposer
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');

        return new MailComposer(
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            $translator,
        );
    }
}
