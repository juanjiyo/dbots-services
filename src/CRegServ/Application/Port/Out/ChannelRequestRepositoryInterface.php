<?php

declare(strict_types=1);

namespace Dbots\CRegServ\Application\Port\Out;

use Dbots\Domain\Registration\ChannelRequest;
use Dbots\Domain\Registration\RequestStatus;

interface ChannelRequestRepositoryInterface
{
    public function find(string $channel): ?ChannelRequest;

    public function save(ChannelRequest $request): void;

    public function delete(string $channel): void;

    /** @return list<ChannelRequest> */
    public function byStatus(RequestStatus $status): array;

    /** @return list<ChannelRequest> */
    public function all(): array;

    public function addAdmin(string $nick): void;

    public function removeAdmin(string $nick): bool;

    public function isAdmin(string $nick): bool;

    /** @return list<string> */
    public function admins(): array;
}
