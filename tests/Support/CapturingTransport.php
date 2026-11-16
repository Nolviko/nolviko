<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Keeps every sent message in memory, or fails like an unreachable server; tests make no network call (M02.02a).
 */
final class CapturingTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $sent = [];

    public function __construct(private readonly ?string $failWith = null)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'capture://memory';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->failWith !== null) {
            throw new TransportException($this->failWith);
        }

        $this->sent[] = $message;
    }
}
