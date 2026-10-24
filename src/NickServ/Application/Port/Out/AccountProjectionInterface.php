<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

interface AccountProjectionInterface
{
    /** Makes a freshly registered account usable for IRCd-native authentication. */
    public function publish(string $nick): void;
}
