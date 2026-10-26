<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\SendNewPassword;

final readonly class SendNewPasswordResult
{
    public const SENT = 'sent';
    public const UNKNOWN_ACCOUNT = 'unknown_account';
    public const NO_EMAIL = 'no_email';

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

    public static function noEmail(): self
    {
        return new self(self::NO_EMAIL);
    }
}
