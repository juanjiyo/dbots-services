<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;

final class InMemoryMemoRepository implements MemoRepositoryInterface
{
    /** @var array<int, Memo> */
    private array $memos = [];

    private int $nextId = 1;

    public function save(Memo $memo): int
    {
        $id = $this->nextId++;
        $this->memos[$id] = new Memo($id, $memo->recipient, $memo->sender, $memo->body, $memo->sentAt);

        return $id;
    }

    public function forRecipient(string $account): array
    {
        return array_values(array_filter(
            $this->memos,
            static fn (Memo $memo): bool => 0 === strcasecmp($memo->recipient, $account),
        ));
    }

    public function find(int $id, string $account): ?Memo
    {
        $memo = $this->memos[$id] ?? null;

        return null !== $memo && 0 === strcasecmp($memo->recipient, $account) ? $memo : null;
    }

    public function markRead(int $id, string $account): void
    {
        $memo = $this->memos[$id] ?? null;

        if (null !== $memo && 0 === strcasecmp($memo->recipient, $account)) {
            $this->memos[$id] = new Memo($memo->id, $memo->recipient, $memo->sender, $memo->body, $memo->sentAt, new \DateTimeImmutable());
        }
    }

    public function delete(int $id, string $account): bool
    {
        $memo = $this->memos[$id] ?? null;

        if (null === $memo || 0 !== strcasecmp($memo->recipient, $account)) {
            return false;
        }

        unset($this->memos[$id]);

        return true;
    }

    public function countUnread(string $account): int
    {
        return count(array_filter(
            $this->forRecipient($account),
            static fn (Memo $memo): bool => $memo->isUnread(),
        ));
    }
}
