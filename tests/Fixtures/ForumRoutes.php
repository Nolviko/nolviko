<?php

declare(strict_types=1);

namespace Nolviko\Tests\Fixtures;

use Nolviko\Core\Routing\RouteDefinition;

/**
 * Forum-shaped route declarations for routing tests and benchmarks (the real ones arrive with their handlers).
 */
final class ForumRoutes
{
    public const ID = '[1-9][0-9]{0,17}';

    public const SLUG = '[a-z0-9][a-z0-9_-]{0,31}';

    /**
     * @return list<RouteDefinition>
     */
    public static function definitions(): array
    {
        return [
            new RouteDefinition('home', '/', ['GET']),
            new RouteDefinition('thread.show', '/threads/{id}', ['GET'], ['id' => self::ID]),
            new RouteDefinition('thread.reply', '/threads/{id}/reply', ['POST'], ['id' => self::ID]),
            new RouteDefinition(
                'thread.page',
                '/threads/{id}/page-{page}',
                ['GET'],
                ['id' => self::ID, 'page' => self::ID],
            ),
            new RouteDefinition('forum.show', '/forums/{id}', ['GET'], ['id' => self::ID]),
            new RouteDefinition('member.show', '/members/{slug}', ['GET'], ['slug' => self::SLUG]),
            new RouteDefinition('search', '/search', ['GET']),
        ];
    }

    /**
     * The forum routes plus enough generated static and dynamic routes to reach $total.
     *
     * @return list<RouteDefinition>
     */
    public static function scaled(int $total): array
    {
        $definitions = self::definitions();

        for ($i = 0; count($definitions) < $total; ++$i) {
            $definitions[] = $i % 2 === 0
                ? new RouteDefinition('static.r' . $i, '/static/r' . $i, ['GET'])
                : new RouteDefinition('section.r' . $i, '/section/r' . $i . '/{id}', ['GET'], ['id' => self::ID]);
        }

        return $definitions;
    }
}
