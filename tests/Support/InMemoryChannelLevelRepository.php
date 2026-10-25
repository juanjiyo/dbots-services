<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use Dbots\Domain\Channel\ChannelLevel;
use Dbots\Domain\Channel\ChannelName;

final class InMemoryChannelLevelRepository implements ChannelLevelRepositoryInterface
{
    /** @var array<string, array<string, int>> */
    private array $levels = [];

    public function levelsFor(ChannelName $channel): array
    {
        return [...ChannelLevel::defaults(), ...($this->levels[$channel->value] ?? [])];
    }

    public function threshold(ChannelName $channel, ChannelLevel $level): int
    {
        return $this->levels[$channel->value][$level->value] ?? $level->default();
    }

    public function set(ChannelName $channel, ChannelLevel $level, int $value): void
    {
        $this->levels[$channel->value][$level->value] = $value;
    }

    public function reset(ChannelName $channel): void
    {
        $this->forget($channel);
    }

    public function forget(ChannelName $channel): void
    {
        unset($this->levels[$channel->value]);
    }
}
