<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountProjectionInterface;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;

use function sprintf;

/**
 * Keeps one account's N records in sync with the IRCd.
 *
 * Every key this projection owns is republished on each change, and any key the
 * account no longer has is deleted — otherwise clearing a suspension would
 * leave the old record behind and the nick would stay suspended.
 */
final readonly class UdbAccountProjection implements AccountProjectionInterface
{
    private const array KEYS = ['pass', 'forbid', 'suspend', 'swhois', 'vhost'];

    public function __construct(
        private AccountRepositoryInterface $accounts,
        private UdbAuthority $authority,
        private AccountBlockProvider $blocks,
    ) {
    }

    public function publish(string $nick): void
    {
        $account = $this->accounts->findByNick(new Nick($nick));

        if (null === $account) {
            return;
        }

        $records = $this->blocks->recordsFor($account);

        foreach (self::KEYS as $key) {
            $path = sprintf('%s::%s', $account->nick->value, $key);

            if (isset($records[$path])) {
                $this->authority->insert('N', $path, $records[$path]);
                continue;
            }

            $this->authority->delete('N', $path);
        }
    }

    public function remove(string $nick): void
    {
        foreach (self::KEYS as $key) {
            $this->authority->delete('N', sprintf('%s::%s', (new Nick($nick))->value, $key));
        }
    }
}
