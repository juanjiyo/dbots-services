<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Approve;

use DateTimeImmutable;

use function sprintf;
use Dbots\ChanServ\Application\Port\Out\ChannelProjectionInterface;
use Dbots\ChanServ\Application\Port\Out\ChannelRepositoryInterface;
use Dbots\CRegServ\Application\Port\Out\ChannelRequestRepositoryInterface;
use Dbots\Domain\Channel\Channel;
use Dbots\Domain\Channel\ChannelName;
use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Memo\Memo;
use Dbots\Domain\Registration\RequestStatus;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use InvalidArgumentException;

final readonly class ApproveHandler
{
    public function __construct(
        private ChannelRequestRepositoryInterface $requests,
        private ChannelRepositoryInterface $channels,
        private ChannelProjectionInterface $projection,
        private MemoRepositoryInterface $memos,
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

        $this->channels->save(new Channel($name, $request->requester, new DateTimeImmutable(), null, $request->passwordHash));
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

        $this->memos->save(new Memo(
            null,
            $request->requester,
            'CReG',
            sprintf(
                'Tu canal %s ha sido ACEPTADO: Recuerda que si el canal incumple alguna norma de la red será borrado AUTOMÁTICAMENTE y sin previo aviso.',
                $request->channel,
            ),
            new DateTimeImmutable(),
        ));

        return ApproveResult::Approved;
    }
}
