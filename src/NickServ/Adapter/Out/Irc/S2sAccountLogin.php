<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Irc;

use Dbots\Application\Port\Out\LinkTransportInterface;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountLoginInterface;

use function sprintf;

/**
 * Tells UnrealIRCd that a connected user authenticated as an account.
 *
 * SVSLOGIN alone associates the account but does not set +r; both lines are
 * required for the user to count as identified.
 */
final readonly class S2sAccountLogin implements AccountLoginInterface
{
    public function __construct(
        private LinkTransportInterface $transport,
        private string $sid,
    ) {
    }

    public function login(string $uid, Nick $account): void
    {
        $this->transport->writeLine(sprintf(':%s SVSLOGIN * %s %s', $this->sid, $uid, $account->value));
        $this->transport->writeLine(sprintf(':%s SVS2MODE %s +r', $this->sid, $uid));
    }

    public function logout(string $uid): void
    {
        $this->transport->writeLine(sprintf(':%s SVSLOGIN * %s *', $this->sid, $uid));
        $this->transport->writeLine(sprintf(':%s SVS2MODE %s -r', $this->sid, $uid));
    }
}
