<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\ValidateRegistration;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\PendingRegistrationRepositoryInterface;
use Dbots\NickServ\Application\RegistrationConfirmation\RegistrationConfirmationSender;
use InvalidArgumentException;

final readonly class ValidateRegistrationHandler
{
    public function __construct(
        private PendingRegistrationRepositoryInterface $pendings,
        private AccountRepositoryInterface $accounts,
        private RegistrationConfirmationSender $confirmation,
    ) {
    }

    public function __invoke(string $nick, string $code): ValidateRegistrationResult
    {
        try {
            $nickname = new Nick($nick);
        } catch (InvalidArgumentException) {
            return ValidateRegistrationResult::InvalidNick;
        }

        $pending = $this->pendings->findByNick($nickname);

        if (null === $pending) {
            return ValidateRegistrationResult::NoPendingRegistration;
        }

        if ($pending->isExpired(new DateTimeImmutable())) {
            return ValidateRegistrationResult::Expired;
        }

        if ($pending->code !== $code) {
            return ValidateRegistrationResult::WrongCode;
        }

        $this->accounts->save(new Account(
            $pending->nick,
            $pending->passwordHash,
            $pending->email,
            new DateTimeImmutable(),
        ));

        if (null !== $pending->email && null !== $pending->plainPassword) {
            $this->confirmation->send($pending->nick->value, $pending->email, $pending->plainPassword);
        }

        $this->pendings->remove($pending->nick);

        return ValidateRegistrationResult::Validated;
    }
}
