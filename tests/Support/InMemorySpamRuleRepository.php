<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Spam\SpamRule;
use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;

final class InMemorySpamRuleRepository implements SpamRuleRepositoryInterface
{
    /** @var array<string, SpamRule> */
    private array $rules = [];

    public function save(SpamRule $rule): void
    {
        $this->rules[$rule->pattern] = $rule;
    }

    public function find(string $pattern): ?SpamRule
    {
        return $this->rules[$pattern] ?? null;
    }

    public function delete(string $pattern): bool
    {
        if (!isset($this->rules[$pattern])) {
            return false;
        }

        unset($this->rules[$pattern]);

        return true;
    }

    public function all(): array
    {
        return array_values($this->rules);
    }
}
