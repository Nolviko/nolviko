<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * The moderator queue read model (M04.07). Every list is newest first, keyset-paginated on its ID with `$before`
 * (rows with a smaller ID) and limited to the forums of `$scope`, filtered in SQL before the LIMIT.
 */
interface ModerationQueue
{
    public const MAX_LIMIT = 100;

    /**
     * Open reports on posts in the scope.
     *
     * @return list<QueueItem>
     */
    public function openReports(ForumScope $scope, int $limit, ?int $before = null): array;

    /**
     * Posts waiting for approval in the scope (threads waiting with their first post included).
     *
     * @return list<QueueItem>
     */
    public function pendingPosts(ForumScope $scope, int $limit, ?int $before = null): array;

    /**
     * Accounts waiting for an administrator's approval (registration mode `admin approval`, M02.03a).
     *
     * @return list<QueueItem>
     */
    public function pendingUsers(int $limit, ?int $before = null): array;
}
