<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

use DateTimeImmutable;
use Dbots\Domain\Account\Password;

/**
 * A registered channel.
 *
 * `founder` is the nickname UDB compares against the connecting user: when the
 * nick matches and the user carries +r, UDB grants the founder +q.
 */
final readonly class Channel
{
    public function __construct(
        public ChannelName $name,
        public string $founder,
        public DateTimeImmutable $createdAt,
        public ?string $topic = null,
        public ?string $passwordHash = null,
        public ?string $description = null,
        public ?string $url = null,
        public ?string $email = null,
        public ?string $entryMessage = null,
        public ?string $suspendReason = null,
        public ?string $suspendedBy = null,
        public ?DateTimeImmutable $suspendedAt = null,
    ) {
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendReason;
    }

    /** The channel password identifies its founder, as in the original CReG. */
    public function authenticatesWith(Password $password): bool
    {
        return null !== $this->passwordHash && $password->matches($this->passwordHash);
    }

    public function isFoundedBy(string $nick): bool
    {
        return 0 === strcasecmp($this->founder, $nick);
    }
}
