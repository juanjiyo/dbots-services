<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;

interface ChannelRepositoryInterface
{
    public function find(ChannelName $name): ?Channel;

    public function save(Channel $channel): void;

    public function updateTopic(Channel $channel): void;

    public function updateFounder(Channel $channel): void;

    public function delete(ChannelName $name): void;

    /** @return list<Channel> */
    public function all(): array;
}
