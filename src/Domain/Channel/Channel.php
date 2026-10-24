<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

use DateTimeImmutable;

/**
 * A registered channel.
 *
 * `founder` is the nickname UDB compares against the connecting user: when the
 * nick matches and the user carries +r, UDB grants the founder +q.
 */
final readonly class Channel
{
    public function __construct(
        public ChannelName $name,
        public string $founder,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isFoundedBy(string $nick): bool
    {
        return 0 === strcasecmp($this->founder, $nick);
    }
}
