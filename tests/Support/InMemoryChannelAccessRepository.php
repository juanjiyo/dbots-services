<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;

final class InMemoryChannelAccessRepository implements ChannelAccessRepositoryInterface
{
    /** @var array<string, array<string, true>> */
    private array $entries = [];

    public function add(string $channel, string $nick): void
    {
        $this->entries[$channel][strtolower($nick)] = true;
    }

    public function remove(string $channel, string $nick): bool
    {
        $key = strtolower($nick);

        if (!isset($this->entries[$channel][$key])) {
            return false;
        }

        unset($this->entries[$channel][$key]);

        return true;
    }

    public function forChannel(string $channel): array
    {
        $nicks = array_keys($this->entries[$channel] ?? []);
        sort($nicks);

        return $nicks;
    }

    public function removeAll(string $channel): void
    {
        unset($this->entries[$channel]);
    }
}
