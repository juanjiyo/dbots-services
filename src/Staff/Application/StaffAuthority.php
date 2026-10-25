<?php

declare(strict_types=1);

namespace Dbots\Staff\Application;

use Dbots\Domain\Staff\StaffRank;
use Dbots\Staff\Application\Port\Out\StaffRankRepositoryInterface;

/**
 * Answers "how much staff authority does this account hold?", which every
 * service asks and none should answer on its own.
 */
final readonly class StaffAuthority
{
    public function __construct(private StaffRankRepositoryInterface $ranks)
    {
    }

    public function levelOf(?string $account): int
    {
        if (null === $account) {
            return 0;
        }

        return $this->ranks->rankOf($account)?->level() ?? 0;
    }

    public function atLeast(?string $account, StaffRank $rank): bool
    {
        return $this->levelOf($account) >= $rank->level();
    }

    /** The original's `$s.n(nick) >= 5`: operator or above. */
    public function isOperator(?string $account): bool
    {
        return $this->levelOf($account) >= StaffRank::operatorLevel();
    }
}
