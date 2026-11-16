<?php

declare(strict_types=1);

namespace Nolviko\Tools\I18n;

/**
 * One i18n check result: where it is and the offending text or message ID.
 */
final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $text,
    ) {
    }

    public function __toString(): string
    {
        return $this->file . ':' . $this->line . ' ' . $this->text;
    }
}
