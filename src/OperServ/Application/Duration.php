<?php

declare(strict_types=1);

namespace Dbots\OperServ\Application;

use function ctype_digit;
use function intdiv;
use function strtolower;
use function substr;

/**
 * Parses ban durations: 0 for permanent, an optional suffix for units, and a
 * bare number meaning minutes (the convention operators expect).
 */
final class Duration
{
    private const array UNITS = [
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    /** Returns seconds, or null when the value cannot be understood. */
    public static function parse(string $value): ?int
    {
        $value = strtolower(trim($value));

        if ('' === $value) {
            return null;
        }

        if ('0' === $value) {
            return 0;
        }

        $unit = substr($value, -1);
        $number = ctype_digit($unit) ? $value : substr($value, 0, -1);

        if ('' === $number || !ctype_digit($number)) {
            return null;
        }

        $multiplier = ctype_digit($unit) ? 60 : (self::UNITS[$unit] ?? null);

        if (null === $multiplier) {
            return null;
        }

        return (int) $number * $multiplier;
    }

    public static function describe(int $seconds): string
    {
        if (0 === $seconds) {
            return 'permanent';
        }

        foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60] as $suffix => $size) {
            if ($seconds >= $size) {
                return intdiv($seconds, $size) . $suffix;
            }
        }

        return $seconds . 's';
    }
}
