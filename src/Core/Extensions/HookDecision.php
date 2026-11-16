<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** A hook can allow, reject with an owned translation key, or suggest validated replacement content. */
final readonly class HookDecision
{
    private function __construct(
        public ?string $reason,
        public ?string $replacement,
    ) {
    }

    public static function allow(): self
    {
        return new self(null, null);
    }

    public static function deny(string $reason): self
    {
        return new self($reason, null);
    }

    public static function replace(string $content): self
    {
        return new self(null, $content);
    }
}
