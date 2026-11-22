<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

use Closure;
use Nolviko\Core\Cache\Cache;

/**
 * Computes the visible-forum set of a subject once per request and shares it between subjects with the same
 * group combination through the cache (ADR-011). The cache key contains the sorted group IDs, the permission
 * key and the rules version, which every rule or forum-tree change bumps, so stale sets are never read. A
 * subject with user overrides is computed on its own and never cached.
 */
final class ForumVisibility
{
    public const VIEW = 'forum.view';

    public const READ = 'forum.thread.view';

    private const TTL_SECONDS = 86400;

    /** @var array<string, ForumScope> */
    private array $memo = [];

    /**
     * @param Closure(): int $rulesVersion
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly Closure $rulesVersion,
    ) {
    }

    /**
     * Forums whose existence the subject may see (lists, navigation).
     */
    public function visible(SubjectPermissions $subject): ForumScope
    {
        return $this->scope($subject, [self::VIEW]);
    }

    /**
     * Forums whose threads and posts the subject may read (thread lists, latest posts, previews, search).
     */
    public function readable(SubjectPermissions $subject): ForumScope
    {
        return $this->scope($subject, [self::VIEW, self::READ]);
    }

    /**
     * @param list<string> $keys All must be allowed.
     */
    private function scope(SubjectPermissions $subject, array $keys): ForumScope
    {
        $groups = implode(',', $subject->groupIds);
        $memoKey = ($subject->userId ?? 'guest') . ':' . $groups . ':' . implode(',', $keys);

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        // Shared sets ignore forum passwords; each visitor's locked forums are removed afterwards (ADR-041).
        $forums = $subject->hasUserOverrides()
            ? self::compute($subject, $keys)
            : $this->cache->remember(
                'visible.' . sha1($groups . '|' . implode(',', $keys)) . '.v' . ($this->rulesVersion)(),
                self::TTL_SECONDS,
                static fn(): array => self::compute($subject, $keys),
            );
        $locked = $keys === [self::VIEW] ? [] : $subject->lockedForums();

        return $this->memo[$memoKey] = new ForumScope(
            $locked === [] ? $forums : array_values(array_diff($forums, $locked)),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<int>
     */
    private static function compute(SubjectPermissions $subject, array $keys): array
    {
        $forums = null;

        foreach ($keys as $key) {
            $allowed = $subject->forumsAllowingUngated($key);
            $forums = $forums === null ? $allowed : array_values(array_intersect($forums, $allowed));
        }

        return $forums ?? [];
    }
}
