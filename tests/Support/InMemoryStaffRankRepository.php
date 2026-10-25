<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Staff\StaffRank;
use Dbots\Staff\Application\Port\Out\StaffRankRepositoryInterface;

final class InMemoryStaffRankRepository implements StaffRankRepositoryInterface
{
    /** @var array<string, StaffRank> */
    private array $ranks = [];

    public function rankOf(string $account): ?StaffRank
    {
        return $this->ranks[strtolower($account)] ?? null;
    }

    public function assign(string $account, StaffRank $rank): void
    {
        $this->ranks[strtolower($account)] = $rank;
    }

    public function revoke(string $account): bool
    {
        $key = strtolower($account);

        if (!isset($this->ranks[$key])) {
            return false;
        }

        unset($this->ranks[$key]);

        return true;
    }

    public function all(): array
    {
        return $this->ranks;
    }
}
