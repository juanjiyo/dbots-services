<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\Port\Out;

interface EmailSenderInterface
{
    public function send(string $to, string $subject, string $body): void;
}
