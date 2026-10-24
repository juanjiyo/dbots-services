<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function strtolower;

final class InMemoryAccountRepository implements AccountRepositoryInterface
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function findByNick(Nick $nick): ?Account
    {
        return $this->accounts[strtolower($nick->value)] ?? null;
    }

    public function save(Account $account): void
    {
        $this->accounts[strtolower($account->nick->value)] = $account;
    }

    public function all(): array
    {
        return array_values($this->accounts);
    }
}
