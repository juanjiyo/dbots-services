<?php

declare(strict_types=1);

namespace Dbots\Udb;

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
    public function __construct(private ChannelRepositoryInterface $channels) {}

    public function records(string $block): array
    {
        if ('C' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->channels->all() as $channel) {
            $records[sprintf('%s::founder', $channel->name->value)] = $channel->founder;
        }

        return $records;
    }
}
