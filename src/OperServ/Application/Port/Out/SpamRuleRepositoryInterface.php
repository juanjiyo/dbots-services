<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Port\Out;

use Dbots\Domain\Spam\SpamRule;

interface SpamRuleRepositoryInterface
{
    public function save(SpamRule $rule): void;

    public function find(string $pattern): ?SpamRule;

    public function delete(string $pattern): bool;

    /** @return list<SpamRule> */
    public function all(): array;
}
