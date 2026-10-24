<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application\Port\Out;

interface EnforcementInterface
{
    public function kill(string $uid, string $reason): void;

    public function kick(string $channel, string $uid, string $reason): void;

    /** Applies a channel mode as the server, optionally with a target parameter. */
    public function channelMode(string $channel, string $modes, ?string $target = null): void;
}
