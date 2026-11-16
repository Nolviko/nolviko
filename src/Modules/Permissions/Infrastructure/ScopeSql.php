<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * Turns a ForumScope into a WHERE condition with bound parameters for DBAL queries.
 */
final class ScopeSql
{
    /**
     * @param string $column A fixed column name such as `t.forum_id`, never input.
     *
     * @return array{0: string, 1: list<list<int>>, 2: list<ArrayParameterType>} The condition with one
     *     placeholder, its parameter and type; `1 = 0` without parameters for an empty scope.
     */
    public static function forums(ForumScope $scope, string $column): array
    {
        if ($scope->forumIds === []) {
            return ['1 = 0', [], []];
        }

        return [$column . ' IN (?)', [$scope->forumIds], [ArrayParameterType::INTEGER]];
    }
}
