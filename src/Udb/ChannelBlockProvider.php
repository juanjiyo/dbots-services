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
    /** UDB `C::options` bit: keep the channel alive with no members. */
    public const int PERSISTENT = 0x08;

    public function __construct(
        private ChannelRepositoryInterface $channels,
    ) {}

    public function records(string $block): array
    {
        if ('C' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->channels->all() as $channel) {
            $records[sprintf('%s::founder', $channel->name->value)] = $channel->founder;

            // A registered channel must survive being empty: otherwise the IRCd
            // destroys it and the first join of the next visitor sends no event.
            $records[sprintf('%s::options', $channel->name->value)] = self::PERSISTENT;

            if ($channel->isSuspended()) {
                $records[sprintf('%s::suspend', $channel->name->value)] = $channel->suspendReason;
            }

            if (null !== $channel->topic && '' !== $channel->topic) {
                $records[sprintf('%s::topic', $channel->name->value)] = $channel->topic;
            }

            // Access ranks stay in SQLite: UDB reads C::<channel>::access as a
            // join allowlist, so publishing them would lock the channel to them.
        }

        return $records;
    }
}
