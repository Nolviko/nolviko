<?php

declare(strict_types=1);

namespace Nolviko\Core\Container;

use LogicException;

/**
 * Service wiring is broken; carries stable diagnostic IDs such as `container_service_missing:<service>:<dep>`.
 */
final class ContainerException extends LogicException
{
    /**
     * @param list<string> $diagnostics
     */
    public function __construct(
        private readonly array $diagnostics,
    ) {
        parent::__construct(implode(',', $diagnostics));
    }

    /**
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
