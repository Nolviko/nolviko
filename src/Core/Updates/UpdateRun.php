<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * Immutable status without lease credentials, transport URLs, server messages or secrets.
 */
final readonly class UpdateRun
{
    public function __construct(
        public string $id,
        public string $target,
        public string $manifestHash,
        public UpdateStage $stage,
        public int $cursor,
        public int $revision,
        public string $condition,
        public ?string $diagnostic,
    ) {
    }
}
