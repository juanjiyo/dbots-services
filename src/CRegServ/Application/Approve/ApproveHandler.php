<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Approve;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;
use InvalidArgumentException;

final readonly class ApproveHandler
{
    public function __construct(
        private ChannelRequestRepositoryInterface $requests,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $projection,
    ) {
    }

    public function __invoke(string $channel, string $staff): ApproveResult
    {
        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return ApproveResult::InvalidChannel;
        }

        $request = $this->requests->find($name->value);

        if (null === $request || !$request->isPending()) {
            return ApproveResult::NoPendingRequest;
        }

        if (null !== $this->channels->find($name)) {
            return ApproveResult::AlreadyRegistered;
        }

        $this->channels->save(new Channel($name, $request->requester, new DateTimeImmutable()));
        $this->projection->publishRegistered($name->value);

        $this->requests->save(new ChannelRequest(
            channel: $request->channel,
            requester: $request->requester,
            description: $request->description,
            status: RequestStatus::Approved,
            requestedAt: $request->requestedAt,
            kind: $request->kind,
            confirmation: $request->confirmation,
            decidedBy: $staff,
            decidedAt: new DateTimeImmutable(),
        ));

        return ApproveResult::Approved;
    }
}
