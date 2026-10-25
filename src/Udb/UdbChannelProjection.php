<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Channel\ChannelName;

use function sprintf;

/**
 * Publishes channel ownership changes as live UDB mutations.
 */
final readonly class UdbChannelProjection implements ChannelProjectionInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channels,
        private UdbAuthority $authority,
    ) {
    }

    public function publishRegistered(string $channel): void
    {
        $registered = $this->channels->find(new ChannelName($channel));

        if (null === $registered) {
            return;
        }

        $this->authority->insert(
            'C',
            sprintf('%s::founder', $registered->name->value),
            $registered->founder,
        );
    }

    public function publishDropped(string $channel): void
    {
        $this->authority->delete('C', sprintf('%s::founder', (new ChannelName($channel))->value));
        $this->authority->delete('C', sprintf('%s::topic', (new ChannelName($channel))->value));
        $this->authority->delete('C', sprintf('%s::suspend', (new ChannelName($channel))->value));
    }

    public function publishAccess(string $channel, string $nick): void
    {
        $this->authority->insert('C', sprintf('%s::access::%s', (new ChannelName($channel))->value, (new Nick($nick))->value), null);
    }

    public function removeAccess(string $channel, string $nick): void
    {
        $this->authority->delete('C', sprintf('%s::access::%s', (new ChannelName($channel))->value, (new Nick($nick))->value));
    }

    public function publishSuspension(string $channel): void
    {
        $registered = $this->channels->find(new ChannelName($channel));

        if (null === $registered) {
            return;
        }

        $path = sprintf('%s::suspend', $registered->name->value);

        if (!$registered->isSuspended()) {
            $this->authority->delete('C', $path);

            return;
        }

        $this->authority->insert('C', $path, (string) $registered->suspendReason);
    }

    public function publishTopic(string $channel): void
    {
        $registered = $this->channels->find(new ChannelName($channel));

        if (null === $registered) {
            return;
        }

        $path = sprintf('%s::topic', $registered->name->value);

        if (null === $registered->topic || '' === $registered->topic) {
            $this->authority->delete('C', $path);

            return;
        }

        $this->authority->insert('C', $path, $registered->topic);
    }
}
