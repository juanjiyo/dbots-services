<?php

declare(strict_types=1);

namespace Dbots\Udb;

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
            // Our stored hash is bcrypt, which is a crypt(3) format.
            $records[sprintf('%s::pass', $account->nick->value)] = 'crypt:' . $account->passwordHash;
        }

        return $records;
    }
}
