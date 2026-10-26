<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\RegisterNick;

use DateTimeImmutable;
use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Blackmail\BlackmailHandler;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;
use Dbots\NickServ\Application\Port\Out\PendingRegistrationRepositoryInterface;
use Dbots\NickServ\Application\RegistrationConfirmation\RegistrationConfirmationSender;
use Dbots\NickServ\Application\Vreg\VregHandler;
use Dbots\NickServ\Domain\PendingRegistration\PendingRegistration;
use InvalidArgumentException;

use function random_int;
use function trim;

final readonly class RegisterNickHandler
{
    private RegistrationConfirmationSender $confirmation;

    public function __construct(
        private AccountRepositoryInterface $accounts,
        private BlackmailHandler $blackmail,
        private VregHandler $vreg,
        private PendingRegistrationRepositoryInterface $pendings,
        private EmailSenderInterface $emailSender,
        string $networkName,
    ) {
        $this->confirmation = new RegistrationConfirmationSender($emailSender, $networkName);
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

        $cleanEmail = null === $email ? null : trim($email);

        if (null !== $cleanEmail && $this->blackmail->isBlacklisted($cleanEmail)) {
            return RegisterNickResult::BlacklistedEmail;
        }

        if ($this->vreg->isEnabled() && null !== $cleanEmail) {
            $code = $this->generateCode();

            $this->pendings->save(new PendingRegistration(
                $nickname,
                $secret->hash(),
                $cleanEmail,
                $code,
                new DateTimeImmutable('+15 minutes'),
                $password,
            ));

            $this->emailSender->send(
                $cleanEmail,
                'Validation code',
                sprintf('Your validation code is: %s', $code),
            );

            return RegisterNickResult::PendingValidation;
        }

        $this->accounts->save(Account::register(
            $nickname,
            $secret,
            $cleanEmail,
            new DateTimeImmutable(),
        ));

        if (null !== $cleanEmail) {
            $this->confirmation->send($nickname->value, $cleanEmail, $password);
        }

        return RegisterNickResult::Registered;
    }

    private function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }
}
