<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\Port\Out;

use Dbots\Domain\Memo\MemoNotifyMode;

interface MemoPreferencesRepositoryInterface
{
    public function getNotifyMode(string $account): MemoNotifyMode;

    public function setNotifyMode(string $account, MemoNotifyMode $mode): void;

    public function isBlacklisted(string $recipient, string $sender): bool;

    public function addToBlacklist(string $recipient, string $sender): void;

    public function removeFromBlacklist(string $recipient, string $sender): bool;

    /**
     * @return list<string>
     */
    public function listBlacklist(string $recipient): array;
}
