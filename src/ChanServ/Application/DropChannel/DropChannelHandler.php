<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\DropChannel;

use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use InvalidArgumentException;

final readonly class DropChannelHandler
{
    public function __construct(private ChannelRepositoryInterface $channels) {}

    public function __invoke(string $channel, ?string $account): DropChannelResult
    {
        if (null === $account) {
            return DropChannelResult::NotFounder;
        }

        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return DropChannelResult::InvalidChannel;
        }

        $registered = $this->channels->find($name);

        if (null === $registered) {
            return DropChannelResult::NotRegistered;
        }

        if (!$registered->isFoundedBy($account)) {
            return DropChannelResult::NotFounder;
        }

        $this->channels->delete($name);

        return DropChannelResult::Dropped;
    }
}
