<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\Port\Out;

use Dbots\Domain\Memo\Memo;

interface MemoRepositoryInterface
{
    public function save(Memo $memo): int;

    /** @return list<Memo> */
    public function forRecipient(string $account): array;

    public function find(int $id, string $account): ?Memo;

    public function markRead(int $id, string $account): void;

    public function delete(int $id, string $account): bool;

    public function countUnread(string $account): int;

    public function lastIdFor(string $account): ?int;

    /** @return list<Memo> */
    public function unreadFor(string $account): array;
}
