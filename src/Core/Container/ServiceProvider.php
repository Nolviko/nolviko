<?php

declare(strict_types=1);

namespace Nolviko\Core\Container;

/**
 * Registers a group of service factories. Core and (from M06) extensions contribute through this contract.
 */
interface ServiceProvider
{
    public function register(ContainerBuilder $services): void;
}
