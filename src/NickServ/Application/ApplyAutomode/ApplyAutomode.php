<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\ApplyAutomode;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Irc\Application\AccountResolver;
use Dbots\Irc\Application\NetworkState;

use function sprintf;

final readonly class ApplyAutomode
{
    public function __construct(
        private AccountResolver $accounts,
        private LinkTransportInterface $transport,
        private string $serviceUid,
    ) {
    }

    public function __invoke(string $uid, NetworkState $state): void
    {
        $account = $this->accounts->resolveAccount($uid, $state);

        if (null === $account || null === $account->automode || '' === $account->automode) {
            return;
        }

        $this->transport->writeLine(sprintf(
            ':%s SVS2MODE %s +%s',
            $this->serviceUid,
            $uid,
            $account->automode,
        ));
    }
}
