<?php

declare(strict_types=1);

namespace Dbots\Staff\Application\Port\Out;

use Dbots\Domain\Staff\StaffRank;

interface StaffRankRepositoryInterface
{
    public function rankOf(string $account): ?StaffRank;

    public function assign(string $account, StaffRank $rank): void;

    public function revoke(string $account): bool;

    /** @return array<string, StaffRank> account => rank */
    public function all(): array;
}
