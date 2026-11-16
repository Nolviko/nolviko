<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/** Escaped before/after lines, not executable HTML; upstream mismatches are explicit publish blockers. */
final class ThemeCompatibility
{
    public static function report(ThemeManifest $manifest, array $files, array $core): array
    {
        $rows = [];
        foreach ($files as $name => $body) {
            if (!str_starts_with($name, 'views/')) {
                continue;
            }
            $template = substr($name, 6);
            $upstream = $core[$template] ?? '';
            $recorded = $manifest->data['upstream'][$template] ?? null;
            $status = $recorded === null ? 'untracked' : (hash_equals($recorded, hash('sha256', $upstream))
                ? 'compatible' : 'changed');
            $rows[] = ['template' => $template, 'status' => $status,
                'upstream' => mb_substr($upstream, 0, 12000), 'override' => mb_substr($body, 0, 12000)];
        }
        return $rows;
    }
}
