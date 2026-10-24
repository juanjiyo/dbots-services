<?php

declare(strict_types=1);

namespace Dbots\OperServ\Adapter\Out\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;

use function sprintf;
use function time;

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

    /**
     * UnrealIRCd TKL: `TKL + G <user> <host> <set-by> <expire> <set-at> :<reason>`,
     * where expire 0 means permanent.
     */
    public function gline(string $userMask, string $hostMask, int $durationSeconds, string $reason): void
    {
        $now = time();

        $this->transport->writeLine(sprintf(
            ':%s TKL + G %s %s %s %d %d :%s',
            $this->sid,
            $userMask,
            $hostMask,
            $this->sid,
            0 === $durationSeconds ? 0 : $now + $durationSeconds,
            $now,
            $reason,
        ));
    }

    public function ungline(string $userMask, string $hostMask): void
    {
        $this->transport->writeLine(sprintf(
            ':%s TKL - G %s %s %s',
            $this->sid,
            $userMask,
            $hostMask,
            $this->sid,
        ));
    }

    public function channelMode(string $channel, string $modes, ?string $target = null): void
    {
        $this->transport->writeLine(null === $target
            ? sprintf(':%s SAMODE %s %s', $this->sid, $channel, $modes)
            : sprintf(':%s SAMODE %s %s %s', $this->sid, $channel, $modes, $target));
    }
}
