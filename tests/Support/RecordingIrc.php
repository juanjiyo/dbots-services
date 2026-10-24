<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountLoginInterface;
use Dbots\NickServ\Application\Port\Out\ReplyInterface;

final class RecordingIrc implements AccountLoginInterface, ReplyInterface
{
    /** @var list<string> */
    public array $logins = [];

    /** @var list<string> */
    public array $logouts = [];

    /** @var list<string> */
    public array $replies = [];

    public function login(string $uid, Nick $account): void
    {
        $this->logins[] = $uid . ':' . $account->value;
    }

    public function logout(string $uid): void
    {
        $this->logouts[] = $uid;
    }

    public function reply(string $targetUid, string $text): void
    {
        $this->replies[] = $targetUid . ': ' . $text;
    }
}
