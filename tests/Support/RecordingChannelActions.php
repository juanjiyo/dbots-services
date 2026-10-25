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

    public function mode(string $channel, string $modes, string $target): void
    {
        $this->modes[] = sprintf('%s %s %s', $channel, $modes, $target);
    }

    public function invite(string $channel, string $uid): void
    {
        $this->invites[] = sprintf('%s %s', $channel, $uid);
    }

    public function notice(string $channel, string $text): void
    {
        $this->notices[] = sprintf('%s: %s', $channel, $text);
    }
}
