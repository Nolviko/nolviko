<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A keyset position in a forum's thread list (ADR-014): the sort key of one row, sticky first, then last
 * activity, with the thread ID as the unique tie-breaker. Encoded as an opaque URL-safe token; decode() accepts
 * only tokens of exactly that shape, so a tampered cursor is refused, never interpreted.
 */
final class ThreadCursor
{
    private const VERSION = '1';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    private const PATTERN = '/^1\|([01])\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6})\|([1-9]\d{0,17})$/D';

    private const MAX_TOKEN_LENGTH = 64;

    public function __construct(
        public readonly bool $sticky,
        public readonly DateTimeImmutable $lastPostAt,
        public readonly int $threadId,
    ) {
    }

    public static function of(ThreadListItem $item): ?self
    {
        return $item->lastPostAt === null ? null : new self($item->sticky, $item->lastPostAt, $item->id);
    }

    public function encode(): string
    {
        $raw = implode('|', [
            self::VERSION,
            $this->sticky ? '1' : '0',
            $this->lastPostAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP),
            (string) $this->threadId,
        ]);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $token): ?self
    {
        if (strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        if ($raw === false || preg_match(self::PATTERN, $raw, $match) !== 1) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat(self::TIMESTAMP, $match[2], new DateTimeZone('UTC'));

        if ($at === false || $at->format(self::TIMESTAMP) !== $match[2]) {
            return null;
        }

        return new self($match[1] === '1', $at, (int) $match[3]);
    }

    public function timestamp(): string
    {
        return $this->lastPostAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
