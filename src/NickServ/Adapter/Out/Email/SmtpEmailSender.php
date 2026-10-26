<?php

declare(strict_types=1);

namespace Dbots\NickServ\Adapter\Out\Email;

use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;
use RuntimeException;

use function mail;

final readonly class SmtpEmailSender implements EmailSenderInterface
{
    public function __construct(
        private string $from,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = [
            'From: ' . $this->from,
            'Reply-To: ' . $this->from,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('Failed to send email via mail().');
        }
    }
}
