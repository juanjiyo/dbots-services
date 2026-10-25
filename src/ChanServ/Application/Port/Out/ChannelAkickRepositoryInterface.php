<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

use Dbots\Domain\Channel\Akick;

interface ChannelAkickRepositoryInterface
{
    public function add(string $channel, Akick $akick): void;

    public function remove(string $channel, string $nick): bool;

    public function has(string $channel, string $nick): bool;

    /** @return list<Akick> */
    public function forChannel(string $channel): array;

    public function countFor(string $channel): int;

    public function removeAll(string $channel): void;
}
