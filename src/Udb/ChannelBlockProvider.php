<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;

use function sprintf;

/**
 * Projects registered channels into the UDB C block.
 *
 * UDB grants the founder +q and marks the channel +r when the connecting nick
 * matches `founder` and the user carries +r. Record paths never include the
 * block letter.
 */
final readonly class ChannelBlockProvider implements BlockProviderInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
    ) {}

    public function records(string $block): array
    {
        if ('C' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->channels->all() as $channel) {
            $records[sprintf('%s::founder', $channel->name->value)] = $channel->founder;

            if ($channel->isSuspended()) {
                $records[sprintf('%s::suspend', $channel->name->value)] = $channel->suspendReason;
            }

            if (null !== $channel->topic && '' !== $channel->topic) {
                $records[sprintf('%s::topic', $channel->name->value)] = $channel->topic;
            }

            foreach ($this->access->forChannel($channel->name->value) as $nick) {
                $records[sprintf('%s::access::%s', $channel->name->value, $nick)] = null;
            }
        }

        return $records;
    }
}
