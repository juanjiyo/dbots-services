<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Application;

use DateTimeImmutable;
use DateTimeZone;

use function preg_match;
use function strtolower;
use function trim;

final readonly class ClonesDuration
{
    public static function parse(string $text): ?DateTimeImmutable
    {
        $text = strtolower(trim($text));

        if (!preg_match('/^(\d+)\s*([dmy])$/', $text, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];

        if ($amount <= 0) {
            return null;
        }

        $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return match ($matches[2]) {
            'd' => $start->modify("+{$amount} days"),
            'm' => $start->modify("+{$amount} months"),
            'y' => $start->modify("+{$amount} years"),
        };
    }
}
