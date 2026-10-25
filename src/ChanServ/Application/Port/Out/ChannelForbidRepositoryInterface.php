<?php

declare(strict_types=1);

namespace Dbots\ChanServ\Application\Port\Out;

interface ChannelForbidRepositoryInterface
{
    public function add(string $channel, string $reason, string $setBy): void;

    public function remove(string $channel): bool;

    public function find(string $channel): ?string;

    /** @return array<string, array{setBy: string, reason: string}> */
    public function all(): array;
}
