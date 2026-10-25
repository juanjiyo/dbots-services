<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Request;

use DateTimeImmutable;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;
use InvalidArgumentException;

final readonly class RequestRegistrationHandler
{
    public function __construct(
        private ChannelRequestRepositoryInterface $requests,
        private ChannelRepositoryInterface $channels,
    ) {
    }

    public function __invoke(string $channel, string $description, ?string $account, bool $operatorInChannel): RequestRegistrationResult
    {
        if (null === $account) {
            return RequestRegistrationResult::NotIdentified;
        }

        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return RequestRegistrationResult::InvalidChannel;
        }

        if (null !== $this->channels->find($name)) {
            return RequestRegistrationResult::AlreadyRegistered;
        }

        $existing = $this->requests->find($name->value);

        if (null !== $existing) {
            return match ($existing->status) {
                RequestStatus::Pending => RequestRegistrationResult::DuplicateRequest,
                RequestStatus::Denied => RequestRegistrationResult::PreviouslyDenied,
                RequestStatus::Approved => RequestRegistrationResult::AlreadyRegistered,
            };
        }

        // The original asks the IRCd who is in the channel and refuses unless the
        // requester holds operator status there: you register what you run.
        if (!$operatorInChannel) {
            return RequestRegistrationResult::NotChannelOperator;
        }

        $this->requests->save(new ChannelRequest(
            $name->value,
            $account,
            '' === $description ? 'Sin descripción' : $description,
            RequestStatus::Pending,
            new DateTimeImmutable(),
        ));

        return RequestRegistrationResult::Requested;
    }
}
