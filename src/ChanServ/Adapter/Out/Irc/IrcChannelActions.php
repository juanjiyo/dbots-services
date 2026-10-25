<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Adapter\Out\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelActionsInterface;

use function sprintf;

/** ChanServ acting as a channel member, from its own service UID. */
final readonly class IrcChannelActions implements ChannelActionsInterface
{
    public function __construct(
        private LinkTransportInterface $transport,
        private string $serviceUid,
    ) {
    }

    public function mode(string $channel, string $modes, string $target): void
    {
        $this->transport->writeLine(sprintf(':%s MODE %s %s %s', $this->serviceUid, $channel, $modes, $target));
    }

    public function invite(string $channel, string $uid): void
    {
        $this->transport->writeLine(sprintf(':%s INVITE %s %s', $this->serviceUid, $uid, $channel));
    }

    public function notice(string $channel, string $text): void
    {
        $this->transport->writeLine(sprintf(':%s NOTICE %s :%s', $this->serviceUid, $channel, $text));
    }
}
