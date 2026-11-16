<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DomainException;

/**
 * The first-admin form was not accepted (M02.15a); `diagnostics` lists every reason as diagnostic IDs.
 */
final class FirstAdminRejected extends DomainException
{
    /**
     * @param list<string> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(implode(',', $diagnostics));
    }
}
