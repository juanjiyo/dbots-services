<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Deny;

use DateTimeImmutable;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Memo\Memo;
use Dbots\Domain\Registration\RequestStatus;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use InvalidArgumentException;

use function sprintf;

final readonly class DenyHandler
{
    public function __construct(
        private ChannelRequestRepositoryInterface $requests,
        private MemoRepositoryInterface $memos,
    ) {}

    public function __invoke(string $channel, string $staff, string $reason): DenyResult
    {
        try {
            $name = new ChannelName($channel);
        } catch (InvalidArgumentException) {
            return DenyResult::InvalidChannel;
        }

        $request = $this->requests->find($name->value);

        if (null === $request || !$request->isPending()) {
            return DenyResult::NoPendingRequest;
        }

        $this->requests->save(new ChannelRequest(
            channel: $request->channel,
            requester: $request->requester,
            description: $request->description,
            status: RequestStatus::Denied,
            requestedAt: $request->requestedAt,
            kind: $request->kind,
            confirmation: $request->confirmation,
            decidedBy: $staff,
            decidedAt: new DateTimeImmutable(),
            reason: '' === $reason ? 'Sin motivo' : $reason,
        ));

        $this->memos->save(new Memo(
            null,
            $request->requester,
            'CReG',
            sprintf('Tu canal %s ha sido DENEGADO: %s', $request->channel, '' === $reason ? 'Sin motivo' : $reason),
            new DateTimeImmutable(),
        ));

        return DenyResult::Denied;
    }
}
