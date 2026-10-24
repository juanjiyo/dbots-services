<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Port\Out;

interface EnforcementInterface
{
    public function kill(string $uid, string $reason): void;

    public function kick(string $channel, string $uid, string $reason): void;
}
