<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Channel\ChannelOption;

interface ChannelOptionRepositoryInterface
{
    public function isEnabled(ChannelName $channel, ChannelOption $option): bool;

    /** @return array<string, string> option => stored value */
    public function optionsFor(ChannelName $channel): array;

    public function set(ChannelName $channel, ChannelOption $option, string $value): void;

    public function forget(ChannelName $channel): void;
}
