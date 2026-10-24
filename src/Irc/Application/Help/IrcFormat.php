<?php

declare(strict_types=1);

namespace Dbots\Irc\Application\Help;

use function sprintf;

/**
 * IRC control codes, using the same palette the original dBots help used:
 * 3 for headers, 12 for command names, 4 for warnings, 10 for labels.
 *
 * The reset code is emitted on its own so the message returns to the client's
 * default colours instead of inheriting ours for the rest of the line.
 */
final class IrcFormat
{
    public const string RESET = "\x03";

    public const int HEADER = 3;
    public const int COMMAND = 12;
    public const int NOTE = 4;
    public const int LABEL = 10;

    public static function paint(int $color, string $text): string
    {
        return sprintf("\x03%02d%s%s", $color, $text, self::RESET);
    }

    /** Removes colour codes; used by tests and by anything that needs plain text. */
    public static function strip(string $text): string
    {
        return (string) preg_replace('/\x03(\d{1,2}(,\d{1,2})?)?/', '', $text);
    }
}
