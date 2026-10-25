<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

interface ChannelProjectionInterface
{
    public function publishRegistered(string $channel): void;

    public function publishDropped(string $channel): void;

    public function publishTopic(string $channel): void;

    public function publishSuspension(string $channel): void;

    public function publishForbid(string $channel, ?string $reason): void;

    /** Repair tool: removes the founder record without touching the rest. */
    public function publishFounderRemoved(string $channel): void;
}
