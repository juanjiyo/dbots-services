<?php

declare(strict_types=1);

namespace Dbots\NoServ\Application\Port\Out;

interface NewsSubscriptionRepositoryInterface
{
    public function subscribe(string $account): void;

    public function unsubscribe(string $account): bool;

    public function isSubscribed(string $account): bool;

    /** @return list<string> */
    public function allSubscribers(): array;
}
