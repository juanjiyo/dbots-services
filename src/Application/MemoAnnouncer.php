<?php

declare(strict_types=1);

namespace Dbots\Application;

use Dbots\MemoServ\Adapter\In\Irc\MemoServCommandHandler;

/**
 * Tells a user about the memos waiting for them the first time they identify.
 * The daemon is immutable, so the bookkeeping lives in this collaborator.
 */
final class MemoAnnouncer
{
    /** @var array<string, true> */
    private array $announced = [];

    public function __construct(private readonly MemoServCommandHandler $memoServ)
    {
    }

    public function announce(string $uid, string $account): void
    {
        if (isset($this->announced[$uid])) {
            return;
        }

        $this->announced[$uid] = true;
        $this->memoServ->notifyPending($uid, $account);
    }
}
