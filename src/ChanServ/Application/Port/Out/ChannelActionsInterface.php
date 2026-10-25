<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

/**
 * Channel actions ChanServ performs as a channel member: setting a rank on
 * somebody, inviting, or talking in the channel.
 */
interface ChannelActionsInterface
{
    public function mode(string $channel, string $modes, string $target): void;

    public function channelModes(string $channel, string $modes): void;

    public function setTopic(string $channel, string $text): void;

    public function invite(string $channel, string $uid): void;

    /** Removes a member as the server does, so it works without channel status. */
    public function kick(string $channel, string $uid, string $reason): void;

    public function notice(string $channel, string $text): void;
}
