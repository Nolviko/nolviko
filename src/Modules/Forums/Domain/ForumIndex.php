<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * The board index read model (M03.09): every forum in the visible scope, with counters and last post, in one
 * query whatever the number of forums.
 */
interface ForumIndex
{
    /**
     * @param ForumScope $visible Forums the reader may see (M02.10 `visible`).
     * @param ForumScope $readable Forums whose last post the reader may see (`readable`); others show none.
     *
     * @return list<ForumIndexEntry> Parents before children, siblings by sort order then ID.
     */
    public function entries(ForumScope $visible, ForumScope $readable): array;
}
