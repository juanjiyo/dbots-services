<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;

final class InMemoryChannelAccessRepository implements ChannelAccessRepositoryInterface
{
    /** @var array<string, array<string, int>> */
    private array $entries = [];

    public function add(string $channel, string $nick, int $level): void
    {
        $this->entries[$channel][strtolower($nick)] = $level;
    }

    public function entries(string $channel): array
    {
        return $this->entries[$channel] ?? [];
    }

    public function levelOf(string $channel, string $nick): ?int
    {
        return $this->entries[$channel][strtolower($nick)] ?? null;
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
