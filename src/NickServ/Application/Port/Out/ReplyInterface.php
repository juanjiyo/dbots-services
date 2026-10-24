<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

interface ReplyInterface
{
    public function reply(string $targetUid, string $text): void;
}
