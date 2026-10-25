<?php

declare(strict_types=1);

namespace Dbots\Irc\Application;

use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

/**
 * Resolves which account a connected user acts as.
 *
 * A client UID frame carries the user modes and the IP but never the account
 * name, and +r arrives after the introduction, so identity is always derived:
 * identified mode present, and the current nick exists in the account store.
 */
final readonly class AccountResolver
{
    public function __construct(private AccountRepositoryInterface $accounts)
    {
    }

    public function resolve(string $uid, NetworkState $state): ?string
    {
        $nick = $state->nickOf($uid);

        if (null === $nick || !$state->hasMode($uid, 'r')) {
            return null;
        }

        try {
            return $this->accounts->findByNick(new Nick($nick))?->nick->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
