<?php

declare(strict_types=1);

namespace Dbots\Irc\Adapter\In;

use function array_shift;
use function explode;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Parses a single IRC protocol line into its parts.
 *
 * Handles message tags (`@a=b`), the optional prefix and the trailing
 * parameter introduced by " :".
 */
final class IrcMessageParser
{
    public function parse(string $line): ?IrcMessage
    {
        $line = trim($line);

        if ('' === $line) {
            return null;
        }

        if (str_starts_with($line, '@')) {
            $space = strpos($line, ' ');

            if (false === $space) {
                return null;
            }

            $line = trim(substr($line, $space + 1));
        }

        $prefix = null;

        if (str_starts_with($line, ':')) {
            $space = strpos($line, ' ');

            if (false === $space) {
                return null;
            }

            $prefix = substr($line, 1, $space - 1);
            $line = trim(substr($line, $space + 1));
        }

        $trailing = null;
        $marker = strpos($line, ' :');

        if (false !== $marker) {
            $trailing = substr($line, $marker + 2);
            $line = substr($line, 0, $marker);
        }

        $parts = explode(' ', $line);

        if ([] === $parts || '' === $parts[0]) {
            return null;
        }

        $command = strtoupper(array_shift($parts));

        return new IrcMessage($prefix, $command, $parts, $trailing);
    }

    public function isBlank(string $line): bool
    {
        return 0 === strlen(trim($line));
    }
}
