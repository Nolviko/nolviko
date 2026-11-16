<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

final readonly class FileSwapProgress
{
    public function __construct(public int $nextIndex, public bool $complete)
    {
    }
}
