<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use Dbots\Domain\Channel\Akick;

final class InMemoryChannelAkickRepository implements ChannelAkickRepositoryInterface
{
    /** @var array<string, array<string, Akick>> */
    private array $akicks = [];

    public function add(string $channel, Akick $akick): void
    {
        $this->akicks[$channel][strtolower($akick->nick)] = $akick;
    }

    public function remove(string $channel, string $nick): bool
    {
        $key = strtolower($nick);

        if (!isset($this->akicks[$channel][$key])) {
            return false;
        }

        unset($this->akicks[$channel][$key]);

        return true;
    }

    public function has(string $channel, string $nick): bool
    {
        return isset($this->akicks[$channel][strtolower($nick)]);
    }

    public function forChannel(string $channel): array
    {
        return array_values($this->akicks[$channel] ?? []);
    }

    public function countFor(string $channel): int
    {
        return \count($this->akicks[$channel] ?? []);
    }

    public function removeAll(string $channel): void
    {
        unset($this->akicks[$channel]);
    }
}
