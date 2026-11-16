<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use DateTimeImmutable;
use Nolviko\Modules\Users\Domain\LoginAttempts;

/**
 * In-memory LoginAttempts (the DBAL one is integration-tested).
 */
final class MemoryLoginAttempts implements LoginAttempts
{
    /** @var list<array{account: string, network: string, succeeded: bool, at: DateTimeImmutable}> */
    public array $rows = [];

    public int $prunes = 0;

    public function failuresForAccount(string $account, DateTimeImmutable $since): array
    {
        return $this->failures('account', $account, $since);
    }

    public function failuresForNetwork(string $network, DateTimeImmutable $since): array
    {
        return $this->failures('network', $network, $since);
    }

    public function record(string $account, string $network, bool $succeeded, ?int $userId, DateTimeImmutable $at): void
    {
        $this->rows[] = ['account' => $account, 'network' => $network, 'succeeded' => $succeeded, 'at' => $at];
    }

    public function clearAccount(string $account): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['account'] !== $account || $row['succeeded'],
        ));
    }

    public function clearNetwork(string $network): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['network'] !== $network,
        ));
    }

    public function prune(DateTimeImmutable $before): void
    {
        ++$this->prunes;
        $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['at'] >= $before));
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function failures(string $field, string $value, DateTimeImmutable $since): array
    {
        $times = [];

        foreach ($this->rows as $row) {
            if ($row[$field] === $value && !$row['succeeded'] && $row['at'] >= $since) {
                $times[] = $row['at'];
            }
        }

        return $times;
    }
}
