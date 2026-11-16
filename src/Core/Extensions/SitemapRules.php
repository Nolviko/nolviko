<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/**
 * What an extension may change in the sitemaps (2026-11-15): leave forums, threads or tag pages out and add
 * addresses of this site. It can only take public pages away; core still decides what guests may read, so a
 * private forum never appears whatever the rules say. Extra addresses must be on the site's own origin.
 */
final readonly class SitemapRules
{
    /**
     * @param list<int> $excludedForums Their forum pages and threads stay out.
     * @param list<int> $excludedThreads
     * @param list<string> $extraUrls Absolute addresses on this site (others are ignored).
     */
    public function __construct(
        public array $excludedForums = [],
        public array $excludedThreads = [],
        public bool $tags = true,
        public bool $forums = true,
        public array $extraUrls = [],
    ) {
    }

    public function excludesForum(int $forumId): bool
    {
        return in_array($forumId, $this->excludedForums, true);
    }

    public function excludesThread(int $threadId, int $forumId): bool
    {
        return in_array($threadId, $this->excludedThreads, true) || $this->excludesForum($forumId);
    }
}
