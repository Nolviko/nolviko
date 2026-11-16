<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use RuntimeException;

/**
 * The configuration is unusable; carries diagnostic IDs such as `config_type_invalid:database.port`.
 *
 * Diagnostics name keys, never values, so they are safe to log and to show on the 503 page.
 */
final class ConfigException extends RuntimeException
{
    /**
     * @param list<string> $diagnostics
     */
    public function __construct(
        private readonly array $diagnostics,
    ) {
        parent::__construct('config_invalid:' . implode(',', $diagnostics));
    }

    /**
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
