<?php

declare(strict_types=1);

namespace Nolviko\Core\Mail;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * PHP `mail()` as a Symfony transport (ADR-028 fallback when no SMTP is configured). The MIME message is built by
 * Symfony Mime; `To` and `Subject` are taken out of the header block because `mail()` writes them itself. The
 * envelope sender is passed as `-f` only when it is a plain address.
 */
final class PhpMailTransport extends AbstractTransport
{
    private readonly Closure $mail;

    /**
     * @param (Closure(string, string, string, string, string): bool)|null $mail Null means PHP `mail()`.
     */
    public function __construct(?Closure $mail = null)
    {
        parent::__construct();
        $this->mail = $mail ?? static fn(string ...$arguments): bool => mail(...$arguments);
    }

    public function __toString(): string
    {
        return 'mail://default';
    }

    protected function doSend(SentMessage $message): void
    {
        [$head, $body] = explode("\r\n\r\n", $message->toString(), 2) + [1 => ''];
        $headers = [];
        $to = '';
        $subject = '';

        foreach (preg_split('/\r\n(?![ \t])/', $head) ?: [] as $line) {
            $name = strtolower(strstr($line, ':', true) ?: '');
            $value = trim((string) preg_replace('/\r\n[ \t]+/', ' ', substr($line, strlen($name) + 1)));

            if ($name === 'to') {
                $to = $value;
            } elseif ($name === 'subject') {
                $subject = $value;
            } else {
                $headers[] = $line;
            }
        }

        $sender = $message->getEnvelope()->getSender()->getAddress();
        $params = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/D', $sender) === 1 ? '-f' . $sender : '';

        if (!($this->mail)($to, $subject, $body, implode("\r\n", $headers), $params)) {
            throw new TransportException('mail_function_failed');
        }
    }
}
