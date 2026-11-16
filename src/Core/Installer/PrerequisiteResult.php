<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\View\ViewModel;

/**
 * One line of the installer prerequisite screen: a status and a translated message with its parameters.
 */
final readonly class PrerequisiteResult implements ViewModel
{
    public const PASSED = 'passed';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    /**
     * @param string $status One of PASSED, WARNING, FAILED.
     * @param string $message Translation ID.
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $status,
        public string $message,
        public array $parameters = [],
    ) {
    }
}
