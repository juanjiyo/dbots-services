<?php

declare(strict_types=1);

namespace Dbots\Domain\Account;

use DateTimeImmutable;

final readonly class Account
{
    public function __construct(
        public Nick $nick,
        public string $passwordHash,
        public ?string $email,
        public DateTimeImmutable $createdAt,
        public ?string $forbidReason = null,
        public ?string $suspendReason = null,
        public ?string $swhois = null,
        public ?string $vhost = null,
        public bool $noexpire = false,
    ) {
    }

    public function isForbidden(): bool
    {
        return null !== $this->forbidReason;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendReason;
    }

    public static function register(Nick $nick, Password $password, ?string $email, DateTimeImmutable $now): self
    {
        return new self($nick, $password->hash(), $email, $now);
    }

    public function authenticatesWith(Password $password): bool
    {
        return $password->matches($this->passwordHash);
    }
}
