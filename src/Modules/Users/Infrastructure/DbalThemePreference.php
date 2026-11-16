<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\View\TemplateException;

/** Only a fixed palette value is stored; the HTTP caller supplies its already authenticated subject ID. */
final readonly class DbalThemePreference
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(int $user): string
    {
        $mode = $this->connection->fetchOne('SELECT theme_preference FROM users WHERE id = ?', [$user]);
        return in_array($mode, ['system', 'light', 'dark'], true) ? $mode : 'system';
    }

    public function set(int $user, string $mode): void
    {
        if ($user < 1 || !in_array($mode, ['system', 'light', 'dark'], true)) {
            throw new TemplateException('theme_preference_invalid');
        }
        $this->connection->executeStatement('UPDATE users SET theme_preference = ? WHERE id = ?', [$mode, $user]);
    }
}
