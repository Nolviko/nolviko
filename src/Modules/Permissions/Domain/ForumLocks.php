<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

use Closure;

/**
 * Password-protected forums (ADR-041) as the permission layer sees them: which forums have a password and which
 * of them the current visitor unlocked in this session. A forum below a protected one is protected by it too.
 * An unlock names the password it was made with (a fingerprint of the stored hash), so a changed or removed
 * password ends every earlier unlock. Background work has no session: nothing is unlocked there.
 */
final class ForumLocks
{
    /** Session entry: forum ID => fingerprint of the password hash it was unlocked with. */
    public const SESSION_KEY = 'forum.unlocked';

    /** @var array<int, string>|null */
    private ?array $protected = null;

    /** @var array<int, string>|null */
    private ?array $unlocked = null;

    /**
     * @param Closure(): array<int, string> $protectedForums Forum ID => fingerprint() of its password hash.
     * @param Closure(): mixed $unlockedForums The session entry (anything; only int => string pairs count).
     */
    public function __construct(
        private readonly Closure $protectedForums,
        private readonly Closure $unlockedForums,
    ) {
    }

    /** The fingerprint an unlock stores: never the hash itself, never the password. */
    public static function fingerprint(string $passwordHash): string
    {
        return substr(hash('sha256', 'nolviko-forum-unlock|' . $passwordHash), 0, 32);
    }

    public function any(): bool
    {
        return $this->protected() !== [];
    }

    /**
     * @param list<int> $chain The forum, then its parent, up to the root (ForumTree::ancestry()).
     * @return int|null The first forum of the chain whose password this visitor has not entered.
     */
    public function blocking(array $chain): ?int
    {
        $protected = $this->protected();

        if ($protected === []) {
            return null;
        }

        $unlocked = $this->unlocked();

        foreach ($chain as $forumId) {
            if (isset($protected[$forumId]) && !hash_equals($protected[$forumId], $unlocked[$forumId] ?? '')) {
                return $forumId;
            }
        }

        return null;
    }

    /** Part of every cache key that holds rendered forum content (empty while no forum has a password). */
    public function identity(): string
    {
        $protected = $this->protected();

        if ($protected === []) {
            return '';
        }

        $open = array_intersect_assoc($this->unlocked(), $protected);
        ksort($open);

        return hash('sha256', serialize([$protected, $open]));
    }

    /** @return array<int, string> */
    private function protected(): array
    {
        return $this->protected ??= ($this->protectedForums)();
    }

    /** @return array<int, string> */
    private function unlocked(): array
    {
        if ($this->unlocked !== null) {
            return $this->unlocked;
        }

        $value = ($this->unlockedForums)();
        $this->unlocked = [];

        foreach (is_array($value) ? $value : [] as $forumId => $fingerprint) {
            if (is_int($forumId) && is_string($fingerprint)) {
                $this->unlocked[$forumId] = $fingerprint;
            }
        }

        return $this->unlocked;
    }
}
