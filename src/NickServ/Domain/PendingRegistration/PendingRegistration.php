<?php

declare(strict_types=1);

namespace Dbots\NickServ\Domain\PendingRegistration;

use DateTimeImmutable;
use Dbots\Domain\Account\Nick;

final readonly class PendingRegistration
{
    public function __construct(
        public Nick $nick,
        public string $passwordHash,
        public ?string $email,
        public string $code,
        public DateTimeImmutable $expiresAt,
        public ?string $plainPassword = null,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
