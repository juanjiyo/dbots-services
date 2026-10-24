<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Domain\Account\Account;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function sprintf;

/**
 * Projects registered accounts into the UDB N block.
 *
 * Record paths never include the block letter: the module reads the block from
 * the frame and hashes the remaining components.
 */
final readonly class AccountBlockProvider implements BlockProviderInterface
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private string $serviceHost,
    ) {}

    /**
     * The N records for one account, keyed by path without the block letter.
     *
     * @return array<string, string>
     */
    public function recordsFor(Account $account): array
    {
        $nick = $account->nick->value;

        // Our stored hash is bcrypt, which is a crypt(3) format.
        $records = [sprintf('%s::pass', $nick) => 'crypt:' . $account->passwordHash];

        if (null !== $account->forbidReason) {
            $records[sprintf('%s::forbid', $nick)] = $account->forbidReason;
        }

        if (null !== $account->suspendReason) {
            $records[sprintf('%s::suspend', $nick)] = $account->suspendReason;
        }

        if (null !== $account->swhois) {
            $records[sprintf('%s::swhois', $nick)] = $account->swhois;
        }

        if (null !== $account->vhost) {
            $records[sprintf('%s::vhost', $nick)] = $account->vhost;
        }

        return $records;
    }

    /** @return array<string, string|int|null> */
    public function records(string $block): array
    {
        if ('S' === $block) {
            // Service mask so IRCd-originated notices come from NickServ and not
            // from the local server source.
            return ['nickserv' => 'NickServ!NickServ@' . $this->serviceHost];
        }

        if ('N' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->accounts->all() as $account) {
            $records += $this->recordsFor($account);
        }

        return $records;
    }
}
