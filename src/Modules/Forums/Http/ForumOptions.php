<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * Forum choices for a <select> (search filter, move target; design pass 2026-11-07) instead of a typed forum
 * number. Tree order from ForumRepository::all(), limited to forums the reader can read; categories are kept as
 * disabled headings so the tree stays legible. Depth is shown with an em-space indent in the label.
 */
final class ForumOptions
{
    /** @return list<array{id: int, label: string, selectable: bool}> */
    public static function readable(ForumRepository $forums, ForumScope $scope): array
    {
        $options = [];

        foreach ($forums->all() as $forum) {
            if (!$scope->contains($forum->id)) {
                continue;
            }

            $options[] = [
                'id' => $forum->id,
                'label' => str_repeat("\u{2003}", $forum->depth) . $forum->title,
                'selectable' => $forum->type === ForumType::Forum,
            ];
        }

        return $options;
    }
}
