<?php

declare(strict_types=1);

namespace Dbots\Domain\Registration;

use DateTimeImmutable;

/**
 * A channel registration request, following the original CReG states:
 *
 *   REGISTRA -> PENDIENTE -> ACEPTADO
 *                        \-> DENEGADO
 *
 * The founder is the account that asked, kept even after a denial so the same
 * channel cannot be re-requested by someone else.
 */
final readonly class ChannelRequest
{
    public function __construct(
        public string $channel,
        public string $requester,
        public string $description,
        public RequestStatus $status,
        public DateTimeImmutable $requestedAt,
        public ChannelKind $kind = ChannelKind::Normal,
        public string $confirmation = '',
        public ?string $decidedBy = null,
        public ?DateTimeImmutable $decidedAt = null,
        public ?string $reason = null,
    ) {
    }

    public function isPending(): bool
    {
        return RequestStatus::Pending === $this->status;
    }

    public function isWaiting(): bool
    {
        return $this->isPending();
    }
}
