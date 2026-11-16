<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Explicit public GitHub repository identity; cannot carry another host, credentials or a path escape.
 */
final readonly class ReleaseRepository
{
    public function __construct(public string $owner, public string $repository)
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/D', $owner) !== 1
            || preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $repository) !== 1
            || in_array($repository, ['.', '..'], true)
        ) {
            throw new RuntimeException('github_repository_invalid');
        }
    }

    public function key(): string
    {
        return 'github.releases.' . hash('sha256', strtolower($this->owner . '/' . $this->repository));
    }

    public function apiUrl(): string
    {
        return 'https://api.github.com/repos/' . rawurlencode($this->owner) . '/'
            . rawurlencode($this->repository) . '/releases?per_page=100';
    }
}
