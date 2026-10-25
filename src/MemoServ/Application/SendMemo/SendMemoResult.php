<?php

declare(strict_types=1);

namespace Dbots\MemoServ\Application\SendMemo;

final readonly class SendMemoResult
{
    public const SENT = 'sent';
    public const UNKNOWN_RECIPIENT = 'unknown_recipient';
    public const EMPTY_BODY = 'empty_body';
    public const NOT_IDENTIFIED = 'not_identified';
    public const BLOCKED = 'blocked';

    public function __construct(
        public string $status,
        public ?int $memoId = null,
    ) {
    }

    public static function sent(int $memoId): self
    {
        return new self(self::SENT, $memoId);
    }

    public static function unknownRecipient(): self
    {
        return new self(self::UNKNOWN_RECIPIENT);
    }

    public static function emptyBody(): self
    {
        return new self(self::EMPTY_BODY);
    }

    public static function notIdentified(): self
    {
        return new self(self::NOT_IDENTIFIED);
    }

    public static function blocked(): self
    {
        return new self(self::BLOCKED);
    }

    public function isSent(): bool
    {
        return self::SENT === $this->status;
    }
}
