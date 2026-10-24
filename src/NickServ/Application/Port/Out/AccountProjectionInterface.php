<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

interface AccountProjectionInterface
{
    /** Makes an account usable for IRCd-native authentication. */
    public function publish(string $nick): void;

    /** Removes the account from the IRCd's database. */
    public function remove(string $nick): void;
}
