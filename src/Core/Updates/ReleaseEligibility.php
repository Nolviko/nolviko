<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Nolviko\Core\Extensions\ComposerVersionConstraint;
use Throwable;

/** Fail closed on unknown platform/addon state; notify-only never becomes unattended activation. */
final class ReleaseEligibility
{
    public function assess(
        ReleaseCompatibility $target,
        ReleaseVersion $current,
        string $channel,
        array $platform,
        array $addons,
        bool $confirmed,
    ): array {
        $issues = [];
        $versions = new ComposerVersionConstraint();
        if (!$target->version->newerThan($current)) {
            $issues[] = 'update_not_newer';
        }
        if (
            !in_array($channel, ['stable', 'prerelease'], true)
            || ($channel === 'stable' && $target->version->channel() !== 'stable')
        ) {
            $issues[] = 'update_channel_mismatch';
        }
        if (!$confirmed) {
            $issues[] = 'update_confirmation_required';
        }
        foreach (['integrity', 'writable', 'backup', 'quiescent', 'schema', 'private_storage'] as $gate) {
            if (($platform[$gate] ?? false) !== true) {
                $issues[] = 'update_' . $gate . '_required';
            }
        }
        try {
            if (!$versions->accepts((string) ($platform['php'] ?? ''), $target->data['php'])) {
                $issues[] = 'update_php_incompatible';
            }
            $constraint = $target->data['databases'][$platform['driver'] ?? ''] ?? null;
            if (
                !is_string($constraint)
                || !$versions->accepts((string) ($platform['database_version'] ?? ''), $constraint)
            ) {
                $issues[] = 'update_database_incompatible';
            }
            foreach ($target->data['extensions'] as $extension) {
                if (!in_array($extension, $platform['extensions'] ?? [], true)) {
                    $issues[] = 'update_php_extension_missing';
                }
            }
            foreach ($addons as $addon) {
                if (
                    !is_array($addon) || !isset($addon['engine'], $addon['php'])
                    || !$versions->accepts($target->data['extension_api'], $addon['engine'])
                    || !$versions->accepts((string) ($platform['php'] ?? ''), $addon['php'])
                ) {
                    $issues[] = 'update_addon_incompatible';
                    continue;
                }
                foreach ($addon['packages'] ?? [] as $package => $required) {
                    if (
                        !isset($target->data['packages'][$package])
                        || !$versions->accepts($target->data['packages'][$package], $required)
                    ) {
                        $issues[] = 'update_addon_package_incompatible';
                    }
                }
            }
        } catch (Throwable) {
            $issues[] = 'update_compatibility_invalid';
        }
        if (
            !is_int($platform['free_bytes'] ?? null) || !is_int($platform['required_bytes'] ?? null)
            || $platform['required_bytes'] < 1 || $platform['free_bytes'] < $platform['required_bytes']
        ) {
            $issues[] = 'update_disk_insufficient';
        }
        return array_values(array_unique($issues));
    }
}
