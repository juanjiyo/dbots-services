<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function sprintf;

/**
 * Projects registered accounts into the UDB N block.
 *
 * The digest is computed over full record paths ("N::<nick>::pass"), which is
 * the same shape the module's reference implementation hashes.
 */
final readonly class AccountBlockProvider implements BlockProviderInterface
{
    public function __construct(private AccountRepositoryInterface $accounts) {}

    public function records(string $block): array
    {
        if ('N' !== $block) {
            return [];
        }

        $records = [];

        foreach ($this->accounts->all() as $account) {
            // Our stored hash is bcrypt, which is a crypt(3) format.
            $records[sprintf('N::%s::pass', $account->nick->value)] = 'crypt:' . $account->passwordHash;
        }

        return $records;
    }
}
