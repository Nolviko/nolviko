<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/**
 * Marker for view DTOs passed to templates. Implementations must be `readonly` classes whose public
 * properties hold only template-safe values (ViewContext); they never carry repositories, connections, the
 * session or other services.
 */
interface ViewModel
{
}
