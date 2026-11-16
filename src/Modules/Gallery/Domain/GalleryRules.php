<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Domain;

/**
 * The administrator's gallery settings (2026-11-08, /admin/site): on/off, largest file, allowed extensions, an
 * optional total per member and optional approval. Defaults for a new forum: on, 10 MB, jpg/jpeg/png/gif/webp,
 * no quota, no approval.
 */
final readonly class GalleryRules
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const DEFAULT_MAX_MB = 10;

    /**
     * @param list<string> $extensions
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxBytes = self::DEFAULT_MAX_MB * 1048576,
        public array $extensions = self::EXTENSIONS,
        public int $quotaBytes = 0,
        public bool $approval = false,
    ) {
    }

    /** Default largest image in a post (MB) and images per post (2026-11-14). */
    public const DEFAULT_POST_MAX_MB = 5;

    public const DEFAULT_POST_IMAGES = 5;

    /**
     * Rules for images added while writing a post (2026-11-14): their own switch and size, the gallery's types,
     * space and approval. Albums may be off while post images are on.
     *
     * @param array<string, string> $settings Stored site settings (SiteSettings::values()).
     */
    public static function forPosts(array $settings): self
    {
        $gallery = self::fromSettings($settings);

        return new self(
            ($settings['posting.images'] ?? 'off') === 'on',
            max(1, (int) ($settings['posting.image_max_mb'] ?? self::DEFAULT_POST_MAX_MB)) * 1048576,
            $gallery->extensions,
            $gallery->quotaBytes,
            $gallery->approval,
        );
    }

    /**
     * @param array<string, string> $settings Stored site settings (SiteSettings::values()).
     */
    public static function fromSettings(array $settings): self
    {
        $extensions = array_values(array_intersect(
            self::EXTENSIONS,
            array_map(static fn(string $one): string => strtolower(trim($one, " .\t")), explode(
                ',',
                $settings['gallery.extensions'] ?? implode(',', self::EXTENSIONS),
            )),
        ));

        return new self(
            ($settings['gallery.enabled'] ?? 'off') === 'on',
            max(1, (int) ($settings['gallery.max_mb'] ?? self::DEFAULT_MAX_MB)) * 1048576,
            $extensions,
            max(0, (int) ($settings['gallery.quota_mb'] ?? 0)) * 1048576,
            ($settings['gallery.approval'] ?? 'off') === 'on',
        );
    }
}
