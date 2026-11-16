<?php

declare(strict_types=1);

namespace Nolviko\Core\Runtime;

use RuntimeException;

/**
 * The runtime prerequisite contract failed; carries the stable diagnostic IDs from config/runtime-policy.php.
 */
final class RuntimeRequirementsException extends RuntimeException
{
    /**
     * @param list<string> $errors Diagnostic IDs such as `php_version_unsupported` or `extension_missing:intl`.
     */
    public function __construct(
        private readonly array $errors,
    ) {
        parent::__construct('runtime_requirements_failed:' . implode(',', $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
