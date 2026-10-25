<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\ChanServ\Application\Port\Out\ChannelForbidRepositoryInterface;

final class InMemoryChannelForbidRepository implements ChannelForbidRepositoryInterface
{
    /** @var array<string, array{setBy: string, reason: string}> */
    private array $forbids = [];

    public function add(string $channel, string $reason, string $setBy): void
    {
        $this->forbids[strtolower($channel)] = ['setBy' => $setBy, 'reason' => $reason];
    }

    public function remove(string $channel): bool
    {
        $key = strtolower($channel);

        if (!isset($this->forbids[$key])) {
            return false;
        }

        unset($this->forbids[$key]);

        return true;
    }

    public function find(string $channel): ?string
    {
        return $this->forbids[strtolower($channel)]['reason'] ?? null;
    }

    public function all(): array
    {
        return $this->forbids;
    }
}
