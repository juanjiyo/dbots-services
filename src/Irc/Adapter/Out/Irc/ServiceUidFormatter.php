<?php

declare(strict_types=1);

namespace Dbots\Irc\Adapter\Out\Irc;

use function sprintf;
use function time;

/**
 * Builds the UID line that introduces a pseudo-client on the network.
 *
 * Field order was captured from a live UnrealIRCd 6.2.7 burst, not guessed:
 *   UID <nick> 0 <ts> <ident> <host> <uid> 0 <modes> <vhost> <cloak> <account> :<realname>
 */
final readonly class ServiceUidFormatter
{
    public function __construct(private string $sid)
    {
    }

    public function format(
        string $nick,
        string $uid,
        string $realname,
        string $ident,
        string $host,
        string $modes = '+Sio',
    ): string {
        return sprintf(
            ':%s UID %s 0 %d %s %s %s 0 %s %s * * :%s',
            $this->sid,
            $nick,
            time(),
            $ident,
            $host,
            $uid,
            $modes,
            $host,
            $realname,
        );
    }
}
