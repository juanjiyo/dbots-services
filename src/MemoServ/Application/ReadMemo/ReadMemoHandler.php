<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\ReadMemo;

use Dbots\Domain\Memo\Memo;
use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;

final readonly class ReadMemoHandler
{
    public function __construct(private MemoRepositoryInterface $memos) {}

    /** Returns the memo and marks it read, or null when it is not the caller's. */
    public function __invoke(int $id, ?string $account): ?Memo
    {
        if (null === $account) {
            return null;
        }

        $memo = $this->memos->find($id, $account);

        if (null === $memo) {
            return null;
        }

        $this->memos->markRead($id, $account);

        return $memo;
    }
}
