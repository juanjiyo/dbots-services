<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use DateTimeImmutable;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\PendingRegistrationRepositoryInterface;
use Dbots\NickServ\Domain\PendingRegistration\PendingRegistration;

final class InMemoryPendingRegistrationRepository implements PendingRegistrationRepositoryInterface
{
    /** @var array<string, PendingRegistration> */
    private array $pendings = [];

    public function save(PendingRegistration $pending): void
    {
        $this->pendings[strtolower($pending->nick->value)] = $pending;
    }

    public function findByNick(Nick $nick): ?PendingRegistration
    {
        return $this->pendings[strtolower($nick->value)] ?? null;
    }

    public function remove(Nick $nick): void
    {
        unset($this->pendings[strtolower($nick->value)]);
    }

    public function expiredBefore(DateTimeImmutable $before): array
    {
        $expired = [];

        foreach ($this->pendings as $pending) {
            if ($pending->expiresAt < $before) {
                $expired[] = $pending;
            }
        }

        return $expired;
    }
}
