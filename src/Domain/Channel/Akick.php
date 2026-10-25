<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

/** An automatic kick: the nick, who put it there and why. */
final readonly class Akick
{
    public function __construct(
        public string $nick,
        public string $reason,
        public string $setBy,
    ) {
    }
}
