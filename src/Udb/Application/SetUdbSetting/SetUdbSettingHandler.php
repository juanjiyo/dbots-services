<?php

declare(strict_types=1);

namespace Dbots\Udb\Application\SetUdbSetting;

use Dbots\Udb\Application\Port\Out\UdbSettingsRepositoryInterface;

use function ctype_alpha;
use function ctype_digit;
use function explode;
use function preg_match;
use function strtoupper;

final readonly class SetUdbSettingHandler
{
    /** @var array<string, string> */
    private const OPTION_KEYS = [
        'SUFIJO' => 'J',
        'SUFFIX' => 'J',
        'NICKSERV' => 'N',
        'CHANSERV' => 'C',
        'IPSERV' => 'I',
        'PASSPROTECT' => 'F',
        'PREFIJOS' => 'P',
        'PREFIXES' => 'P',
    ];

    public function __construct(private UdbSettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(string $option, string $value): SetUdbSettingResult
    {
        $key = self::OPTION_KEYS[strtoupper($option)] ?? null;

        if (null === $key) {
            return SetUdbSettingResult::unknownOption();
        }

        $error = $this->validate($key, $value);

        if (null !== $error) {
            return SetUdbSettingResult::invalidValue($error);
        }

        $this->settings->set($key, $value);

        return SetUdbSettingResult::set($key);
    }

    private function validate(string $key, string $value): ?string
    {
        return match ($key) {
            'J' => $this->validateSuffix($value),
            'N', 'C', 'I' => $this->validateServiceMask($value),
            'F' => $this->validatePassProtect($value),
            'P' => $this->validatePrefixes($value),
            default => 'Unknown setting key.',
        };
    }

    private function validateSuffix(string $value): ?string
    {
        if ('' === $value || !ctype_alpha($value)) {
            return 'The suffix must contain letters only.';
        }

        return null;
    }

    private function validateServiceMask(string $value): ?string
    {
        if (1 !== preg_match('/^[^!\s]+![^@\s]+@[^\s]+$/', $value)) {
            return 'Syntax: nick!ident@host';
        }

        return null;
    }

    private function validatePassProtect(string $value): ?string
    {
        $parts = explode(':', $value);

        if (2 !== count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return 'Syntax: attempts:seconds (e.g. 3:60).';
        }

        return null;
    }

    private function validatePrefixes(string $value): ?string
    {
        if ('' === $value) {
            return 'The prefix string cannot be empty.';
        }

        return null;
    }
}
