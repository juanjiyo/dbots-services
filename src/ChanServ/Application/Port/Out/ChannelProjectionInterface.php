<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

interface ChannelProjectionInterface
{
    public function publishRegistered(string $channel): void;

    public function publishDropped(string $channel): void;

    public function publishAccess(string $channel, string $nick): void;

    public function removeAccess(string $channel, string $nick): void;

    public function publishTopic(string $channel): void;
}
