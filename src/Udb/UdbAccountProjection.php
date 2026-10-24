<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function sprintf;

final readonly class UdbAccountProjection implements AccountProjectionInterface
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private UdbAuthority $authority,
    ) {
    }

    public function publish(string $nick): void
    {
        $account = $this->accounts->findByNick(new Nick($nick));

        if (null === $account) {
            return;
        }

        $this->authority->insertPassword($account->nick->value, sprintf('crypt:%s', $account->passwordHash));
    }

    public function remove(string $nick): void
    {
        $this->authority->delete('N', sprintf('%s::pass', (new Nick($nick))->value));
    }
}
