<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\Out\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;

use function sprintf;

/** Server-side enforcement: a ULine server may kill and sa-kick directly. */
final readonly class S2sEnforcement implements EnforcementInterface
{
    public function __construct(
        private LinkTransportInterface $transport,
        private string $sid,
    ) {
    }

    public function kill(string $uid, string $reason): void
    {
        $this->transport->writeLine(sprintf(':%s KILL %s :%s', $this->sid, $uid, $reason));
    }

    public function kick(string $channel, string $uid, string $reason): void
    {
        $this->transport->writeLine(sprintf(':%s SAKICK %s %s :%s', $this->sid, $channel, $uid, $reason));
    }
}
