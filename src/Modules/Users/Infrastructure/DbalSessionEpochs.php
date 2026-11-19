<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Users\Domain\SessionEpochs;

/**
 * SessionEpochs on `users.session_epoch` (primary-key lookup).
 */
final class DbalSessionEpochs implements SessionEpochs
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function revokeSessionsAndRememberTokens(int $userId): void
    {
        $this->connection->transactional(static function (Connection $db) use ($userId): void {
            $db->executeStatement('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
            $db->delete('remember_tokens', ['user_id' => $userId]);
        });
    }

    public function current(int $userId): ?int
    {
        $epoch = $this->connection->fetchOne(
            "SELECT session_epoch FROM users WHERE id = ? AND status = 'active'",
            [$userId],
        );

        return $epoch === false ? null : (int) $epoch;
    }
}
