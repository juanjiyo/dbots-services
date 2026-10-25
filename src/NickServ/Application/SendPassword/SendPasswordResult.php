<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\SendPassword;

final readonly class SendPasswordResult
{
    public const SENT = 'sent';
    public const UNKNOWN_ACCOUNT = 'unknown_account';

    public function __construct(
        public string $status,
        public ?string $account = null,
        public ?string $password = null,
    ) {
    }

    public static function sent(string $account, string $password): self
    {
        return new self(self::SENT, $account, $password);
    }

    public static function unknownAccount(): self
    {
        return new self(self::UNKNOWN_ACCOUNT);
    }
}
