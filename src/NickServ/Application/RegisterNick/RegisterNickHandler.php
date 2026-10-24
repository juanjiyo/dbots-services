<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\RegisterNick;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function trim;

final readonly class RegisterNickHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
    ) {
    }

    public function __invoke(string $nick, string $password, ?string $email): RegisterNickResult
    {
        try {
            $nickname = new Nick($nick);
        } catch (InvalidArgumentException) {
            return RegisterNickResult::InvalidNick;
        }

        try {
            $secret = Password::fromPlain($password);
        } catch (InvalidArgumentException) {
            return RegisterNickResult::WeakPassword;
        }

        if (null !== $this->accounts->findByNick($nickname)) {
            return RegisterNickResult::AlreadyRegistered;
        }

        $this->accounts->save(Account::register(
            $nickname,
            $secret,
            null === $email ? null : trim($email),
            new DateTimeImmutable(),
        ));

        return RegisterNickResult::Registered;
    }
}
