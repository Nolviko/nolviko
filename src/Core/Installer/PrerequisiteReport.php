<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\View\ViewModel;

/**
 * Everything the prerequisite screen shows, grouped as on the page.
 */
final readonly class PrerequisiteReport implements ViewModel
{
    public bool $canContinue;

    /**
     * @param list<PrerequisiteResult> $runtime PHP version, extensions, memory limit.
     * @param list<PrerequisiteResult> $files Storage writability and web exposure of private directories.
     */
    public function __construct(
        public array $runtime,
        public array $files,
    ) {
        $failed = array_filter(
            [...$runtime, ...$files],
            static fn(PrerequisiteResult $result): bool => $result->status === PrerequisiteResult::FAILED,
        );
        $this->canContinue = $failed === [];
    }
}
