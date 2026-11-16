<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use RuntimeException;

/**
 * A message ID is missing from the English catalog: a build defect, reported as a stable diagnostic ID.
 */
final class MissingMessageException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self('translation_missing:' . $id);
    }

    public static function invalidId(string $id): self
    {
        return new self('translation_id_invalid:' . $id);
    }
}
