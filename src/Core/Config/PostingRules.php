<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

/**
 * Site-wide content anti-spam settings (M03.15a), from `posting.*` and `antispam.*` in config/local.php.
 *
 * - firstPostsModerated: with `forum.post.approval` = `first_posts`, a user's posts wait for approval until this
 *   many of them are visible.
 * - New users (fewer than linkMinPosts visible posts, or an account younger than linkMinAgeHours) may put at
 *   most newUserMaxLinks links or images into one post.
 * - blockedWords (whole words, case-insensitive) and blockedDomains (the domain and its subdomains) refuse a post.
 */
final readonly class PostingRules
{
    public const DEFAULT_FIRST_POSTS = 1;

    public const DEFAULT_LINK_MIN_POSTS = 3;

    public const DEFAULT_LINK_MIN_AGE_HOURS = 24;

    public const DEFAULT_NEW_USER_MAX_LINKS = 0;

    /** Images in one post (site setting `posting.images_per_post`, 2026-11-14). */
    public const DEFAULT_MAX_IMAGES = 5;

    /**
     * @param list<string> $blockedWords
     * @param list<string> $blockedDomains Lowercase host names.
     */
    public function __construct(
        public int $firstPostsModerated = self::DEFAULT_FIRST_POSTS,
        public int $linkMinPosts = self::DEFAULT_LINK_MIN_POSTS,
        public int $linkMinAgeHours = self::DEFAULT_LINK_MIN_AGE_HOURS,
        public int $newUserMaxLinks = self::DEFAULT_NEW_USER_MAX_LINKS,
        public array $blockedWords = [],
        public array $blockedDomains = [],
        public int $maxImages = self::DEFAULT_MAX_IMAGES,
    ) {
    }
}
