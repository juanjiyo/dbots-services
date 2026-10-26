<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Email;

use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailerEmailSender implements EmailSenderInterface
{
    private Mailer $mailer;

    public function __construct(
        private string $from,
        string $dsn,
    ) {
        $this->mailer = new Mailer(Transport::fromDsn($dsn));
    }

    public function send(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}
