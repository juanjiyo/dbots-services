<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

interface ChannelAccessRepositoryInterface
{
    public function add(string $channel, string $nick, int $level): void;

    /** @return array<string, int> nick => level */
    public function entries(string $channel): array;

    public function levelOf(string $channel, string $nick): ?int;

    public function remove(string $channel, string $nick): bool;

    /** @return list<string> */
    public function forChannel(string $channel): array;

    public function removeAll(string $channel): void;
}
