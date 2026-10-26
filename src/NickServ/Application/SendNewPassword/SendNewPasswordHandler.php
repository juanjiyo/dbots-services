<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\SendNewPassword;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Domain\Account\Password;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;
use InvalidArgumentException;

use function bin2hex;
use function random_bytes;
use function sprintf;

final readonly class SendNewPasswordHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private EmailSenderInterface $emailSender,
        private string $from,
    ) {
    }

    public function __invoke(string $targetNick): SendNewPasswordResult
    {
        try {
            $nick = new Nick($targetNick);
        } catch (InvalidArgumentException) {
            return SendNewPasswordResult::unknownAccount();
        }

        $account = $this->accounts->findByNick($nick);

        if (null === $account) {
            return SendNewPasswordResult::unknownAccount();
        }

        if (null === $account->email || '' === $account->email) {
            return SendNewPasswordResult::noEmail();
        }

        $plain = $this->generatePassword();
        $password = Password::fromPlain($plain);

        $this->accounts->update(new Account(
            $account->nick,
            $password->hash(),
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
            $account->url,
            $account->quitVisible,
            $account->private,
            $account->kill,
            $account->automode,
        ));

        $this->emailSender->send(
            $account->email,
            sprintf('[%s] Contraseña temporal restablecida', $account->nick->value),
            sprintf(
                "Hola %s,\n\n" .
                "Un operador ha restablecido la contraseña de tu cuenta.\n" .
                "Tu contraseña temporal es: %s\n\n" .
                "Identifícate con ella y cámbiala cuanto antes mediante:\n\n" .
                "/msg NiCK SET PASSWORD %s <nueva_contraseña>\n\n" .
                "Por favor, no contestes. Este es un mensaje automático enviado por NiCK <%s>.\n\n" .
                'El Equipo de la red',
                $account->nick->value,
                $plain,
                $plain,
                $this->from,
            ),
        );

        return SendNewPasswordResult::sent($account->nick->value, $plain);
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(8));
    }
}
