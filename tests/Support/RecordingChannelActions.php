<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelActionsInterface;

final class RecordingChannelActions implements ChannelActionsInterface
{
    /** @var list<string> */
    public array $modes = [];

    /** @var list<string> */
    public array $invites = [];

    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $channelModeLines = [];

    /** @var list<string> */
    public array $topics = [];

    /** @var list<string> */
    public array $kicks = [];

    public function mode(string $channel, string $modes, string $target): void
    {
        $this->modes[] = sprintf('%s %s %s', $channel, $modes, $target);
    }

    public function channelModes(string $channel, string $modes): void
    {
        $this->channelModeLines[] = sprintf('%s %s', $channel, $modes);
    }

    public function setTopic(string $channel, string $text): void
    {
        $this->topics[] = sprintf('%s: %s', $channel, $text);
    }

    public function invite(string $channel, string $uid): void
    {
        $this->invites[] = sprintf('%s %s', $channel, $uid);
    }

    public function kick(string $channel, string $uid, string $reason): void
    {
        $this->kicks[] = sprintf('%s %s: %s', $channel, $uid, $reason);
    }

    public function notice(string $channel, string $text): void
    {
        $this->notices[] = sprintf('%s: %s', $channel, $text);
    }
}
