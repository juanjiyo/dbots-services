<?php

declare(strict_types=1);

namespace Dbots\Domain\Memo;

use DateTimeImmutable;

/**
 * A memo belongs to its recipient's account, never to a connected session, so
 * it survives nick changes, disconnections and restarts.
 */
final readonly class Memo
{
    public function __construct(
        public ?int $id,
        public string $recipient,
        public string $sender,
        public string $body,
        public DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $readAt = null,
    ) {
    }

    public function isUnread(): bool
    {
        return null === $this->readAt;
    }
}
