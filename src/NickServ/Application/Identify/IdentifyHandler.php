<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Identify;

use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

final readonly class IdentifyHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
    ) {
    }

    public function __invoke(string $nick, string $password): IdentifyResult
    {
        try {
            $nickname = new Nick($nick);
        } catch (InvalidArgumentException) {
            return IdentifyResult::InvalidNick;
        }

        $account = $this->accounts->findByNick($nickname);

        if (null === $account) {
            return IdentifyResult::UnknownAccount;
        }

        try {
            $secret = Password::fromPlain($password);
        } catch (InvalidArgumentException) {
            // A password below the minimum cannot match any stored hash; report
            // it as wrong instead of leaking the password policy on login.
            return IdentifyResult::WrongPassword;
        }

        if (!$account->authenticatesWith($secret)) {
            return IdentifyResult::WrongPassword;
        }

        return IdentifyResult::Identified;
    }
}
