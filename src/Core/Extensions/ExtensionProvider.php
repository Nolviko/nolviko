<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Explicit extension entry point; registration is additive through the owned context. */
interface ExtensionProvider
{
    public function register(ExtensionContext $context): void;
}
