<?php

declare(strict_types=1);

namespace Nolviko\Core\Settings;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\PostingRules;

/**
 * The anti-spam rules in force (2026-11-08): a value `config/local.php` sets (anything other than the built-in
 * default) wins, as everywhere in ADR-034; otherwise the administrator's value from `/admin/site`; otherwise the
 * default. Lists are comma-separated in the settings store, at most MAX_ENTRIES entries.
 */
final class EffectivePostingRules
{
    public const MAX_ENTRIES = 200;

    public static function of(?Config $config, ?SiteSettings $settings): PostingRules
    {
        $file = $config?->postingRules ?? new PostingRules();
        $default = new PostingRules();
        $stored = $settings === null ? [] : $settings->values();
        $int = static fn(int $configured, int $builtIn, string $key): int => $configured !== $builtIn
            ? $configured : (isset($stored[$key]) ? (int) $stored[$key] : $builtIn);

        return new PostingRules(
            $int($file->firstPostsModerated, $default->firstPostsModerated, 'antispam.first_posts_moderated'),
            $int($file->linkMinPosts, $default->linkMinPosts, 'antispam.link_min_posts'),
            $file->linkMinAgeHours,
            $int($file->newUserMaxLinks, $default->newUserMaxLinks, 'antispam.new_user_max_links'),
            $file->blockedWords !== [] ? $file->blockedWords : self::words($stored['antispam.blocked_words'] ?? ''),
            $file->blockedDomains !== [] ? $file->blockedDomains
                : array_map('strtolower', self::words($stored['antispam.blocked_domains'] ?? '')),
            max(1, (int) ($stored['posting.images_per_post'] ?? PostingRules::DEFAULT_MAX_IMAGES)),
        );
    }

    /**
     * @return list<string>
     */
    public static function censored(?SiteSettings $settings): array
    {
        return self::words($settings?->get('antispam.censored_words') ?? '');
    }

    /**
     * @return list<string>
     */
    public static function words(string $list): array
    {
        $words = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $list)),
            static fn(string $word): bool => $word !== '',
        )));

        return array_slice($words, 0, self::MAX_ENTRIES);
    }
}
