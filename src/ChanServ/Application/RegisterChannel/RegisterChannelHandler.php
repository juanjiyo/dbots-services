<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\RegisterChannel;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use InvalidArgumentException;

final readonly class RegisterChannelHandler
{
    public function __construct(private ChannelRepositoryInterface $channels) {}

    /**
     * @param string|null $account the sender's registered account, null when not identified
     */
    public function __invoke(string $channel, ?string $account): RegisterChannelResult
    {
        if (null === $account) {
            return RegisterChannelResult::NotIdentified;
        }

        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return RegisterChannelResult::InvalidChannel;
        }

        if (null !== $this->channels->find($name)) {
            return RegisterChannelResult::AlreadyRegistered;
        }

        $this->channels->save(new Channel($name, $account, new DateTimeImmutable()));

        return RegisterChannelResult::Registered;
    }
}
