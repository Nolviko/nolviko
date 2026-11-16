<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use RuntimeException;

/** Stable diagnostic boundary for extension validation and lifecycle failures. */
final class ExtensionFailure extends RuntimeException
{
}
