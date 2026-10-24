<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;

use function sprintf;

final readonly class IrcReplySender implements ReplyInterface
{
    public function __construct(
        private LinkTransportInterface $transport,
        private string $serviceUid,
    ) {
    }

    public function reply(string $targetUid, string $text): void
    {
        $this->transport->writeLine(sprintf(':%s NOTICE %s :%s', $this->serviceUid, $targetUid, $text));
    }
}
