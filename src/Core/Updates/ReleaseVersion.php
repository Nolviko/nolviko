<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Nolviko\Core\Extensions\ComposerVersionConstraint;
use RuntimeException;

/** Strict SemVer tags; discovery hints never authorize a downgrade or channel change. */
final readonly class ReleaseVersion
{
    public string $value;

    public function __construct(string $tag)
    {
        $value = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
        if (
            strlen($value) > 100 || preg_match(
                '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
                . '(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.'
                . '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?'
                . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
                $value,
            ) !== 1
        ) {
            throw new RuntimeException('update_version_invalid');
        }
        $this->value = $value;
    }

    public function newerThan(self $installed): bool
    {
        return (new ComposerVersionConstraint())->accepts($this->value, '>' . $installed->value);
    }

    public function channel(): string
    {
        return str_contains(explode('+', $this->value, 2)[0], '-') ? 'prerelease' : 'stable';
    }

    public static function select(array $releases, self $installed, string $channel): ?DiscoveredRelease
    {
        if (!in_array($channel, ['stable', 'prerelease'], true)) {
            throw new RuntimeException('update_channel_invalid');
        }
        $selected = null;
        $version = $installed;
        foreach ($releases as $release) {
            if (!$release instanceof DiscoveredRelease) {
                continue;
            }
            try {
                $candidate = new self($release->tag);
            } catch (RuntimeException) {
                continue;
            }
            if (
                $release->prerelease !== ($candidate->channel() === 'prerelease')
                || ($channel === 'stable' && $candidate->channel() !== 'stable')
            ) {
                continue;
            }
            if ($candidate->newerThan($version)) {
                $selected = $release;
                $version = $candidate;
            }
        }
        return $selected;
    }
}
