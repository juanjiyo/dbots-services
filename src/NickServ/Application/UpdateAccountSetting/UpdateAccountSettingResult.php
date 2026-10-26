<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\UpdateAccountSetting;

final readonly class UpdateAccountSettingResult
{
    public const CHANGED = 'changed';
    public const NOT_IDENTIFIED = 'not_identified';
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

    public static function notIdentified(): self
    {
        return new self(self::NOT_IDENTIFIED);
    }

    public static function invalidValue(string $error): self
    {
        return new self(self::INVALID_VALUE, $error);
    }
}
