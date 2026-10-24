<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
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
    }
}
