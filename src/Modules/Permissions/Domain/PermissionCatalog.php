<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * Every permission the code knows, declared in code (`core()`; extensions will add theirs in M06).
 *
 * Unknown keys: in development (strict) asking for one is a programming error and throws; in production
 * `find()` returns null so the caller denies. The database copy (`permissions`) is synced from this list
 * after migrations and is never the source of truth.
 */
final class PermissionCatalog
{
    /** @var array<string, PermissionDefinition> */
    private readonly array $definitions;

    /**
     * @param list<PermissionDefinition> $definitions
     * @param bool $strict True in development: unknown keys throw instead of being denied.
     *
     * @throws PermissionError `permission_key_duplicate:<key>`.
     */
    public function __construct(array $definitions, public readonly bool $strict = false)
    {
        $byKey = [];

        foreach ($definitions as $definition) {
            if (isset($byKey[$definition->key])) {
                throw new PermissionError('permission_key_duplicate:' . $definition->key);
            }

            $byKey[$definition->key] = $definition;
        }

        ksort($byKey);
        $this->definitions = $byKey;
    }

    /**
     * The core MVP permissions (forums, posting, moderation, administration).
     */
    public static function core(bool $strict = false): self
    {
        return new self([
            PermissionDefinition::bool('forum.view'),
            PermissionDefinition::bool('forum.thread.view'),
            PermissionDefinition::bool('forum.thread.create'),
            PermissionDefinition::bool('forum.post.reply'),
            PermissionDefinition::bool('forum.post.edit_own'),
            PermissionDefinition::bool('forum.post.delete_own'),
            PermissionDefinition::int('forum.post.edit_window_minutes', 0, 10080),
            PermissionDefinition::int('forum.post.flood_interval_seconds', 0, 3600, PermissionDefinition::LOWER),
            PermissionDefinition::enum('forum.post.approval', ['all', 'first_posts', 'none']),
            PermissionDefinition::bool('forum.post.report'),
            PermissionDefinition::bool('user.profile.view'),
            PermissionDefinition::bool('user.directory.view'),
            PermissionDefinition::bool('moderation.profile.manage'),
            PermissionDefinition::bool('moderation.post.hide'),
            PermissionDefinition::bool('moderation.post.edit_any'),
            PermissionDefinition::bool('moderation.thread.lock'),
            PermissionDefinition::bool('moderation.thread.sticky'),
            PermissionDefinition::bool('moderation.thread.move'),
            PermissionDefinition::bool('moderation.report.handle'),
            PermissionDefinition::bool('moderation.user.ban'),
            PermissionDefinition::bool('admin.access'),
            // 2026-11-15: members of a group with this rule must set up a second sign-in step (when one exists).
            PermissionDefinition::bool('user.second_factor.required'),
        ], $strict);
    }

    /**
     * @throws PermissionError `permission_unknown:<key>` when strict and the key is not declared.
     */
    public function find(string $key): ?PermissionDefinition
    {
        $definition = $this->definitions[$key] ?? null;

        if ($definition === null && $this->strict) {
            throw new PermissionError('permission_unknown:' . $key);
        }

        return $definition;
    }

    /**
     * @return array<string, PermissionDefinition> Keyed and sorted by permission key.
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
