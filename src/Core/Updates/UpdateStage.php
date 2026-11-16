<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * Ordered durable update boundaries; advancing records completed work, never executes it by itself.
 */
enum UpdateStage: string
{
    case Discovered = 'discovered';
    case Checked = 'checked';
    case Downloaded = 'downloaded';
    case Verified = 'verified';
    case Staged = 'staged';
    case BackedUp = 'backed_up';
    case Maintenance = 'maintenance';
    case FilesSwitched = 'files_switched';
    case MigrationsApplied = 'migrations_applied';
    case SmokeChecked = 'smoke_checked';
    case Completed = 'completed';

    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[$index + 1] ?? null;
    }
}
