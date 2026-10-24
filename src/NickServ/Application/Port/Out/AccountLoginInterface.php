<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

use Dbots\Domain\Account\Nick;

/**
 * Makes the IRCd know that a connected user is authenticated as an account.
 */
interface AccountLoginInterface
{
    public function login(string $uid, Nick $account): void;

    public function logout(string $uid): void;
}
