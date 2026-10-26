<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\UpdateAutomode;

final readonly class UpdateAutomodeResult
{
    public const CHANGED = 'changed';
    public const CLEARED = 'cleared';
    public const NOT_IDENTIFIED = 'not_identified';
    public const NOT_AUTHORIZED = 'not_authorized';
    public const UNKNOWN_ACCOUNT = 'unknown_account';
    public const INVALID_VALUE = 'invalid_value';

    public function __construct(
        public string $status,
        public ?string $error = null,
    ) {
    }

    public static function changed(): self
    {
        return new self(self::CHANGED);
    }

    public static function cleared(): self
    {
        return new self(self::CLEARED);
    }

    public static function notIdentified(): self
    {
        return new self(self::NOT_IDENTIFIED);
    }

    public static function notAuthorized(): self
    {
        return new self(self::NOT_AUTHORIZED);
    }

    public static function unknownAccount(): self
    {
        return new self(self::UNKNOWN_ACCOUNT);
    }

    public static function invalidValue(string $error): self
    {
        return new self(self::INVALID_VALUE, $error);
    }
}
