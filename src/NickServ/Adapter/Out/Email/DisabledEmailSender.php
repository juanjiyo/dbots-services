<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Email;

use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;

final readonly class DisabledEmailSender implements EmailSenderInterface
{
    public function send(string $to, string $subject, string $body): void
    {
        // Email is disabled in this environment; nothing is sent.
    }
}
