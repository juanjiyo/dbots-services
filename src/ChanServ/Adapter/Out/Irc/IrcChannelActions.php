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
        private string $sid,
    ) {
    }

    public function mode(string $channel, string $modes, string $target): void
    {
        $this->transport->writeLine(sprintf(':%s MODE %s %s %s', $this->serviceUid, $channel, $modes, $target));
    }

    public function channelModes(string $channel, string $modes): void
    {
        $this->transport->writeLine(sprintf(':%s MODE %s %s', $this->serviceUid, $channel, $modes));
    }

    public function setTopic(string $channel, string $text): void
    {
        $this->transport->writeLine(sprintf(':%s TOPIC %s :%s', $this->serviceUid, $channel, $text));
    }

    public function invite(string $channel, string $uid): void
    {
        $this->transport->writeLine(sprintf(':%s INVITE %s %s', $this->serviceUid, $uid, $channel));
    }

    public function kick(string $channel, string $uid, string $reason): void
    {
        $this->transport->writeLine(sprintf(':%s SAKICK %s %s :%s', $this->sid, $channel, $uid, $reason));
    }

    public function notice(string $channel, string $text): void
    {
        $this->transport->writeLine(sprintf(':%s NOTICE %s :%s', $this->serviceUid, $channel, $text));
    }
}
