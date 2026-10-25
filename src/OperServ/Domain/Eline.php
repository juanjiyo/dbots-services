<?php

declare(strict_types=1);

namespace Dbots\OperServ\Domain;

/**
 * An E-Line exception: an IP or host allowed to bypass network limits.
 */
final readonly class Eline
{
    public function __construct(
        public ?int $id,
        public string $mask,
        public string $reason,
        public string $who,
        public int $createdAt,
        public int $expiresAt,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return 0 !== $this->expiresAt && $this->expiresAt <= $now;
    }
}
