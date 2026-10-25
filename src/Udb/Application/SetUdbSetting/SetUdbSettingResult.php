<?php

declare(strict_types=1);

namespace Dbots\Udb\Application\SetUdbSetting;

final readonly class SetUdbSettingResult
{
    public const SET = 'set';
    public const UNKNOWN_OPTION = 'unknown_option';
    public const INVALID_VALUE = 'invalid_value';

    public function __construct(
        public string $status,
        public ?string $key = null,
        public ?string $error = null,
    ) {
    }

    public static function set(string $key): self
    {
        return new self(self::SET, $key);
    }

    public static function unknownOption(): self
    {
        return new self(self::UNKNOWN_OPTION);
    }

    public static function invalidValue(string $error): self
    {
        return new self(self::INVALID_VALUE, error: $error);
    }
}
