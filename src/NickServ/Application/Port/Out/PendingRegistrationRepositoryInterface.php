<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Domain\PendingRegistration\PendingRegistration;

interface PendingRegistrationRepositoryInterface
{
    public function save(PendingRegistration $pending): void;

    public function findByNick(Nick $nick): ?PendingRegistration;

    public function remove(Nick $nick): void;

    /**
     * @return list<PendingRegistration>
     */
    public function expiredBefore(\DateTimeImmutable $before): array;
}
