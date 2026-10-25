<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

use Dbots\Domain\Channel\ChannelLevel;
use Dbots\Domain\Channel\ChannelName;

interface ChannelLevelRepositoryInterface
{
    /** @return array<string, int> level name => threshold, defaults included */
    public function levelsFor(ChannelName $channel): array;

    public function threshold(ChannelName $channel, ChannelLevel $level): int;

    public function set(ChannelName $channel, ChannelLevel $level, int $value): void;

    public function reset(ChannelName $channel): void;

    public function forget(ChannelName $channel): void;
}
