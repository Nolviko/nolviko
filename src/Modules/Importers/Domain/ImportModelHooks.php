<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

/**
 * Optional mappings onto forum features that arrive in beta.18 from other modules (link and password forums, post
 * icons; later smileys, profile field choices, forum subscriptions, messages with several or hidden recipients).
 * The import target calls `apply()` only for a feature the hooks `supports()`; without hooks (or for a feature they
 * do not support) the target keeps its own conservative fallback and notes the record for review. The data passed
 * is already checked by the target: IDs are target IDs, text is bounded; never credentials or password hashes.
 */
interface ImportModelHooks
{
    /** A link forum: `url` (http or https, at most 2000 bytes). Fallback: an ordinary forum, noted for review. */
    public const FORUM_LINK = 'forum_link';

    /** A forum the old site protected with a password. Fallback: staff only, noted for review. */
    public const FORUM_PASSWORD = 'forum_password';

    /** A post's icon: `icon` (the source's icon name or number). Fallback: kept in the import map only. */
    public const POST_ICON = 'post_icon';

    public function supports(string $feature): bool;

    /**
     * @param int|string $target the target ID the feature applies to (a forum, a post)
     * @param array<string, scalar> $data
     */
    public function apply(string $feature, int|string $target, array $data): void;
}
