<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Cancel;

use DateTimeImmutable;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;
use InvalidArgumentException;

final readonly class CancelHandler
{
    public function __construct(private ChannelRequestRepositoryInterface $requests)
    {
    }

    /** The requester withdraws their own pending request. */
    public function __invoke(string $channel, ?string $account): bool
    {
        if (null === $account) {
            return false;
        }

        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return false;
        }

        $request = $this->requests->find($name->value);

        if (null === $request || !$request->isPending() || 0 !== strcasecmp($request->requester, $account)) {
            return false;
        }

        $this->requests->save(new ChannelRequest(
            channel: $request->channel,
            requester: $request->requester,
            description: $request->description,
            status: RequestStatus::Denied,
            requestedAt: $request->requestedAt,
            kind: $request->kind,
            confirmation: $request->confirmation,
            decidedBy: $account,
            decidedAt: new DateTimeImmutable(),
            reason: 'Cancelada por el solicitante',
        ));

        return true;
    }
}
