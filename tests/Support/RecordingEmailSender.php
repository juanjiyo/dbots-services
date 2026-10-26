<?php

declare(strict_types=1);

namespace Dbots\Tests\Support;

use Dbots\NickServ\Application\Port\Out\EmailSenderInterface;

final class RecordingEmailSender implements EmailSenderInterface
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
