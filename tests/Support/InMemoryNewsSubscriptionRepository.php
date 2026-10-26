<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NoServ\Application\Port\Out\NewsSubscriptionRepositoryInterface;

final class InMemoryNewsSubscriptionRepository implements NewsSubscriptionRepositoryInterface
{
    /** @var array<string, true> */
    private array $subscribers = [];

    public function subscribe(string $account): void
    {
        $this->subscribers[$this->normalize($account)] = true;
    }

    public function unsubscribe(string $account): bool
    {
        $key = $this->normalize($account);

        if (!isset($this->subscribers[$key])) {
            return false;
        }

        unset($this->subscribers[$key]);

        return true;
    }

    public function isSubscribed(string $account): bool
    {
        return isset($this->subscribers[$this->normalize($account)]);
    }

    public function allSubscribers(): array
    {
        $accounts = array_keys($this->subscribers);
        sort($accounts);

        return $accounts;
    }

    private function normalize(string $account): string
    {
        return strtolower(trim($account));
    }
}
