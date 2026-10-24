<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;

interface AccountRepositoryInterface
{
    public function findByNick(Nick $nick): ?Account;

    public function save(Account $account): void;

    public function update(Account $account): void;

    public function delete(Nick $nick): void;

    /** @return list<Account> */
    public function all(): array;
}
