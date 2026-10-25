<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Domain;

use DateTimeImmutable;

final readonly class ClonesException
{
    public function __construct(
        public ?int $id,
        public string $host,
        public int $clones,
        public string $type,
        public string $author,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
    ) {
    }

    public function isPermanent(): bool
    {
        return null === $this->expiresAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }
}
