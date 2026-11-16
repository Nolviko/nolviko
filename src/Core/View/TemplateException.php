<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use RuntimeException;
use Throwable;

/**
 * A theme or template problem; the message is a diagnostic ID such as `template_missing:<name>`.
 */
final class TemplateException extends RuntimeException
{
    public function __construct(string $diagnostic, ?Throwable $previous = null)
    {
        parent::__construct($diagnostic, 0, $previous);
    }
}
