<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0012: `posts.rendered_html`, the ADR-012 render cache next to `renderer_version`. NULL until a post is first
 * shown; expand only.
 */
final class PostsRenderedHtmlColumn implements Migration
{
    public const ID = '0012_posts_rendered_html';

    public function id(): string
    {
        return self::ID;
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return ['ALTER TABLE posts ADD COLUMN rendered_html MEDIUMTEXT NULL AFTER renderer_version'];
    }
}
