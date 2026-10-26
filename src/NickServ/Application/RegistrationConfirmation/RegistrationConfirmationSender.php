<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\RegistrationConfirmation;

use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;

use function sprintf;

final readonly class RegistrationConfirmationSender
{
    public function __construct(
        private EmailSenderInterface $emailSender,
        private string $networkName,
    ) {
    }

    public function send(string $nick, string $email, string $password): void
    {
        $subject = sprintf('[%s] Has registrado el nick %s', $this->networkName, $nick);
        $body = sprintf(
            "Hola %s,\n\n" .
            "Gracias por registrar tu Nick en el chat de %s.\n" .
            "Este nick caducará si no te autentificas por primera vez antes de 72 horas.\n" .
            "Todos los nicks no utilizados en un periodo de 210 días expiran por falta de uso.\n\n" .
            "Los datos de tu nick son:\n\n" .
            "Nick: %s\n" .
            "Contraseña: %s\n" .
            "Para identificarte:\n\n" .
            "/nick %s:%s\n\n" .
            "Para cambiar la contraseña:\n\n" .
            "/msg NiCK SET PASSWORD nueva_contraseña\n\n" .
            "Para cambiar la contraseña por una segura y aleatoria:\n\n" .
            "/msg NiCK SENDNEWPASS\n\n" .
            "Por favor, no contestes. Este es un mensaje automático.\n\n" .
            'El Equipo de %s',
            $nick,
            $this->networkName,
            $nick,
            $password,
            $nick,
            $password,
            $this->networkName,
        );

        $this->emailSender->send($email, $subject, $body);
    }
}
