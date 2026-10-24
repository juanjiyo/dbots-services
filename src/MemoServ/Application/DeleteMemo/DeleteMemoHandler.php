<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\DeleteMemo;

use Dbots\MemoServ\Application\Port\Out\MemoRepositoryInterface;

final readonly class DeleteMemoHandler
{
    public function __construct(private MemoRepositoryInterface $memos) {}

    public function __invoke(int $id, ?string $account): bool
    {
        return null !== $account && $this->memos->delete($id, $account);
    }
}
